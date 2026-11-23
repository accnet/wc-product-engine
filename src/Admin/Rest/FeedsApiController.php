<?php

declare(strict_types=1);

namespace WPE\Admin\Rest;

use RuntimeException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedConfig;
use WPE\Application\Feeds\FeedMapper;
use WPE\Application\Feeds\FeedRepository;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Application\Feeds\FeedValidator;
use WPE\Application\Feeds\FeedWriter;
use WPE\Application\Feeds\FeedValidationException;
use WPE\Support\Capabilities;
use WPE\Support\RestErrors;
use WPE\Support\Singleton;

/**
 * Admin REST routes for product feeds (04 §10).
 *
 * Reading needs `manage_woocommerce`; every change needs `wpe_manage_operations`, because a feed
 * publishes the catalog outside the site. URLs carry the access token, so they are only returned
 * to users who may change feeds.
 */
class FeedsApiController
{
    use Singleton;

    public const NAMESPACE = 'wpe/v1';

    private FeedRepository $feeds;

    public function __construct(?FeedRepository $feeds = null)
    {
        $this->feeds = $feeds ?? new FeedRepository();
    }

    public function registerRoutes(): void
    {
        $id = ['id' => ['validate_callback' => static fn($v): bool => is_numeric($v) && (int)$v > 0]];

        register_rest_route(self::NAMESPACE, '/admin/feeds', [
            ['methods' => 'GET', 'callback' => [$this, 'listFeeds'], 'permission_callback' => [$this, 'canView']],
            ['methods' => 'POST', 'callback' => [$this, 'createFeed'], 'permission_callback' => [$this, 'canManage']],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'getFeed'], 'permission_callback' => [$this, 'canView'], 'args' => $id],
            ['methods' => 'PATCH', 'callback' => [$this, 'updateFeed'], 'permission_callback' => [$this, 'canManage'], 'args' => $id],
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteFeed'], 'permission_callback' => [$this, 'canManage'], 'args' => $id],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)/duplicate', [
            'methods' => 'POST', 'callback' => [$this, 'duplicateFeed'], 'permission_callback' => [$this, 'canManage'], 'args' => $id,
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)/rotate-token', [
            'methods' => 'POST', 'callback' => [$this, 'rotateToken'], 'permission_callback' => [$this, 'canManage'], 'args' => $id,
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)/regenerate', [
            'methods' => 'POST', 'callback' => [$this, 'regenerate'], 'permission_callback' => [$this, 'canManage'], 'args' => $id,
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)/preview', [
            'methods' => 'GET', 'callback' => [$this, 'preview'], 'permission_callback' => [$this, 'canView'], 'args' => $id,
        ]);
        register_rest_route(self::NAMESPACE, '/admin/feeds/(?P<id>\d+)/diagnostics', [
            'methods' => 'GET', 'callback' => [$this, 'diagnostics'], 'permission_callback' => [$this, 'canView'], 'args' => $id,
        ]);
    }

    /** Products up to which an admin rebuild runs inside the request (same threshold as the catalog index). */
    public const INLINE_REBUILD_MAX_PRODUCTS = 2000;

    /** Time box of one `continue` step driven by the open admin screen. */
    public const STEP_SECONDS = 20.0;

    /**
     * Publish now (from the item store) or request a full rebuild. Small catalogs rebuild inside the
     * request; larger ones return 202 queued. `continue` advances a requested or running build by one
     * time-boxed step (the open Edit screen drives it right after Create, Save or Full rebuild, so a
     * feed builds without waiting for the worker) and never starts a new one.
     */
    public function regenerate(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->requireFeed($request);
            $mode = (string)($this->body($request)['mode'] ?? '');
            if (!in_array($mode, ['publish', 'rebuild', 'continue'], true)) {
                throw new \WPE\Application\Feeds\FeedValidationException('Unknown mode.', [['pointer' => '/mode', 'reason' => 'Must be publish, rebuild or continue.']]);
            }
            $builder = $this->builder();
            $id = (int)$feed['id'];

            if ($mode === 'continue') {
                $pending = (int)$feed['build_generation'] > 0 || (string)($feed['rebuild_reason'] ?? '') !== '';
                if (!$pending) {
                    return new WP_REST_Response(['id' => $id, 'status' => 'idle', 'feed' => $this->feeds->view($feed, true)], 200);
                }
                $res = $builder->buildStep($id, self::STEP_SECONDS);
                $view = $this->feeds->view($this->feeds->get($id), true);
                if ($res['status'] === 'completed') {
                    return new WP_REST_Response(['id' => $id, 'status' => 'completed', 'result' => $res, 'feed' => $view], 200);
                }
                return new WP_REST_Response(['id' => $id, 'status' => 'building', 'result' => $res, 'feed' => $view], 202);
            }

            if ($mode === 'rebuild' || (int)$feed['active_generation'] === 0) {
                if ((int)$feed['build_generation'] === 0) {
                    $this->feeds->requestRebuild($id, 'manual');
                }
                if ($builder->source()->countProducts() <= self::INLINE_REBUILD_MAX_PRODUCTS) {
                    $res = $builder->buildStep($id, 25.0);
                    if ($res['status'] === 'completed') {
                        return new WP_REST_Response(['id' => $id, 'status' => 'completed', 'result' => $res, 'feed' => $this->feeds->view($this->feeds->get($id), true)], 200);
                    }
                }
                return new WP_REST_Response([
                    'id' => $id, 'status' => 'queued',
                    'message' => __('The rebuild runs in the background worker.', 'wc-product-engine'),
                    'feed' => $this->feeds->view($this->feeds->get($id), true),
                ], 202);
            }

            $res = $builder->publish($id, true);
            $status = $res['status'] === 'published' ? 200 : 409;
            return new WP_REST_Response(['id' => $id, 'status' => $res['status'], 'code' => $status === 200 ? null : 'conflict', 'result' => $res, 'feed' => $this->feeds->view($this->feeds->get($id), true),
                'message' => $res['status'] === 'published' ? __('Feed published.', 'wc-product-engine') : (string)($res['reason'] ?? '')], $status);
        });
    }

    /**
     * First items of the feed with their issues and the rendered file fragment. Before the first
     * build, items are rendered live from the first products of the catalog.
     */
    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->requireFeed($request);
            $limit = max(1, min(50, (int)($request->get_param('limit') ?: 20)));
            $builder = $this->builder();
            $gen = (int)$feed['active_generation'];
            $source = 'store';
            if ($gen > 0) {
                $items = $builder->store()->first((int)$feed['id'], $gen, $limit);
            } else {
                $source = 'live';
                $items = [];
                $mapper = new FeedMapper($feed);
                $cursor = 0;
                $guard = 0;
                while (count($items) < $limit && $guard++ < 20) {
                    $ids = $builder->source()->nextProductIds($cursor, 25);
                    if ($ids === []) {
                        break;
                    }
                    $cursor = (int)end($ids);
                    $records = $builder->source()->load($ids, FeedMapper::needs($feed['config']));
                    foreach ($ids as $pid) {
                        foreach ($mapper->map($pid, $records[$pid] ?? null) as $item) {
                            $items[] = $item;
                        }
                    }
                }
                $items = array_slice($items, 0, $limit);
            }

            $tmp = (string)tempnam(get_temp_dir(), 'wpe-feed-preview');
            $writer = new FeedWriter($feed['format'], (new FeedMapper($feed))->columns());
            $writer->open($tmp, ['title' => $feed['name'], 'link' => home_url('/'), 'description' => get_bloginfo('name')]);
            foreach ($items as $item) {
                if ($item['eligible']) {
                    $writer->write($item['payload']);
                }
            }
            $writer->close();
            $rendered = (string)file_get_contents($tmp);
            @unlink($tmp);

            $out = array_map(static fn(array $i): array => [
                'product_id' => (int)$i['product_id'],
                'variant_id' => (int)$i['variant_id'],
                'item_id'    => (string)$i['item_id'],
                'eligible'   => (bool)$i['eligible'],
                'payload'    => $i['payload'],
                'issues'     => array_map(static fn(string $c): array => ['code' => $c, 'severity' => FeedValidator::severity($c)], (array)$i['issues']),
                'edit_url'   => get_edit_post_link((int)$i['product_id'], 'raw'),
            ], $items);

            return new WP_REST_Response(['items' => $out, 'source' => $source, 'rendered' => mb_substr($rendered, 0, 200000)], 200);
        });
    }

    /**
     * Issue counts by code with sample items, feed-level warnings, run history and worker heartbeat.
     */
    public function diagnostics(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->requireFeed($request);
            $diag = (array)($feed['stats']['diagnostics'] ?? []);
            $issues = [];
            foreach ((array)($diag['issues'] ?? []) as $code => $count) {
                $issues[$code] = [
                    'count'    => (int)$count,
                    'severity' => FeedValidator::severity((string)$code),
                    'samples'  => array_map(static fn(array $s): array => $s + ['edit_url' => get_edit_post_link((int)$s['product_id'], 'raw')],
                        array_slice((array)($diag['samples'][$code] ?? []), 0, 10)),
                ];
            }
            uasort($issues, static fn(array $a, array $b): int => [$a['severity'] !== 'error', -$a['count']] <=> [$b['severity'] !== 'error', -$b['count']]);

            $warnings = [];
            $country = (string)($feed['config']['market']['country'] ?? '');
            $storeInclusive = get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_prices_include_tax') === 'yes';
            if (FeedConfig::marketExpectsTaxInclusivePrices($country) && !$storeInclusive) {
                $warnings[] = ['code' => 'tax_inclusive_market', 'message' => __('This market expects prices that include tax, but store prices exclude it.', 'wc-product-engine')];
            }
            $hb = FeedScheduler::lastHeartbeat();
            if ($hb === null || $hb['age'] > 300) {
                $warnings[] = ['code' => 'worker_not_running', 'message' => __('The feed worker has not run in the last 5 minutes.', 'wc-product-engine')];
            }
            if (!empty($feed['stats']['last_gate_failure']) && $feed['status'] === 'error') {
                $warnings[] = ['code' => 'publish_rejected', 'message' => (string)$feed['stats']['last_gate_failure']['reason']];
            }

            return new WP_REST_Response([
                'issues'    => $issues,
                'totals'    => ['items' => (int)($diag['total'] ?? 0), 'eligible' => (int)($diag['eligible'] ?? 0), 'at' => $diag['at'] ?? null],
                'warnings'  => $warnings,
                'runs'      => $this->builder()->runs((int)$feed['id'], 20),
                'worker'    => $hb ?? ['at' => null, 'age' => null],
                'reconcile' => $feed['stats']['reconcile']['last_cycle'] ?? null,
                'dirty'     => $this->builder()->store()->dirtyCount((int)$feed['id']),
            ], 200);
        });
    }

    private ?FeedBuilder $builderInstance = null;

    private function builder(): FeedBuilder
    {
        return $this->builderInstance ??= new FeedBuilder(null, $this->feeds);
    }

    public function canView(): bool
    {
        return current_user_can('manage_woocommerce') || Capabilities::canRunOperations();
    }

    public function canManage(): bool
    {
        return Capabilities::canRunOperations();
    }

    public function listFeeds(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feeds = $this->feeds->list([
                'search'   => (string)$request->get_param('search'),
                'platform' => (string)$request->get_param('platform'),
                'status'   => (string)$request->get_param('status'),
                'orderby'  => (string)$request->get_param('orderby'),
                'order'    => (string)$request->get_param('order'),
            ]);
            $withUrl = $this->canManage();
            $items = array_map(fn(array $f): array => $this->feeds->view($f, $withUrl), $feeds);
            return new WP_REST_Response(['items' => $items, 'total' => count($items)], 200);
        });
    }

    public function createFeed(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->feeds->create($this->body($request));
            return new WP_REST_Response($this->feeds->view($feed, true), 201);
        });
    }

    public function getFeed(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            return new WP_REST_Response($this->feeds->view($this->requireFeed($request), $this->canManage()), 200);
        });
    }

    public function updateFeed(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $body = $this->body($request);
            $expected = isset($body['expected_version']) ? (int)$body['expected_version'] : null;
            if ($expected === null) {
                throw new RuntimeException('expected_version is required.', 428);
            }
            unset($body['expected_version']);
            $res = $this->feeds->update((int)$request->get_param('id'), $body, $expected);
            $view = $this->feeds->view($res['feed'], true);
            $view['rebuild_required'] = $res['rebuild'];
            return new WP_REST_Response($view, 200);
        });
    }

    public function deleteFeed(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->requireFeed($request);
            $this->feeds->delete((int)$feed['id']);
            return new WP_REST_Response(['id' => (int)$feed['id'], 'status' => 'deleted'], 200);
        });
    }

    public function duplicateFeed(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $copy = $this->feeds->duplicate((int)$this->requireFeed($request)['id']);
            return new WP_REST_Response($this->feeds->view($copy, true), 201);
        });
    }

    public function rotateToken(WP_REST_Request $request): WP_REST_Response
    {
        return $this->run(function () use ($request): WP_REST_Response {
            $feed = $this->feeds->rotateToken((int)$this->requireFeed($request)['id']);
            return new WP_REST_Response($this->feeds->view($feed, true), 200);
        });
    }

    protected function requireFeed(WP_REST_Request $request): array
    {
        $feed = $this->feeds->get((int)$request->get_param('id'));
        if (!$feed) {
            throw new RuntimeException('Feed not found.', 404);
        }
        return $feed;
    }

    protected function body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        return is_array($json) ? $json : (array)$request->get_body_params();
    }

    /**
     * Run a handler and map failures to the contract error envelope (04 §1.6).
     */
    protected function run(callable $handler): WP_REST_Response
    {
        try {
            return $handler();
        } catch (FeedValidationException $e) {
            return $this->error('validation_failed', $e->getMessage(), 422, $e->details());
        } catch (Throwable $e) {
            if (RestErrors::isClientError($e)) {
                $status = (int)$e->getCode();
                $code = match ($status) {
                    404     => 'not_found',
                    409     => 'conflict',
                    412     => 'stale_version',
                    428     => 'precondition_required',
                    default => 'invalid_request',
                };
                return $this->error($code, $e->getMessage(), in_array($status, [404, 409, 412, 428], true) ? $status : 400);
            }
            return $this->error(
                'dependency_unavailable',
                RestErrors::safeMessage($e, 'feeds api', __('The feed request could not be completed.', 'wc-product-engine')),
                503
            );
        }
    }

    protected function error(string $code, string $message, int $status, array $details = []): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message, 'details' => $details], $status);
    }
}
