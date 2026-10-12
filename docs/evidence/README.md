# Evidence

## 1. Runtime result contract

Result JSON: task_id, commit, content_digest from scripts/evidence-lib.mjs, environment exact wordpress/woocommerce/php/database versions, commands array of argv/display/exit_code/output_path/output_sha256/content_digest, regression_results requirement ID/status/command_ref/test_name/assertion_summary, acceptance IDs from task.acceptance_checks + observation/result/artifact, blockers array, files_implemented array. `qa_status=passed` only if real commands exit0 and every task requirement/acceptance pass. Output files private-safe; no stdout fabricated as template. All dependency result artifacts must pass for implementation_complete→qa_passed.

## 2. Docs evidence

docs-validation.json logs docs checker/plan checker/guard tests and independent review. Đây là evidence cho docs creation, không T01–T49 runtime test result. Không tạo result.json giả để lấp placeholder paths.

## 3. Freshness scope

Task đang verify phải khớp current task Files + contracts + authoritative numbered docs digest. Dependency receipts xác thực historical successful output/test/acceptance, không yêu cầu digest global hiện tại (tránh T03 sealing invalidate bootstrap trước completion). Contract/code changes ảnh hưởng dependencies phải reopen/rerun task tương ứng theo CHANGELOG; receipt checker không tự thay independent semantic review. Plan done freshness luôn so current declared files/contracts/docs digest, kể cả no-git.

## 4. Reproduce initial-package QA

Python 3 standard library + Node ≥18, không thêm dependency:

```bash
python3 scripts/docs-qa/tooling-selftest.py
python3 scripts/docs-qa/contract-selftest.py
```

Chỉ dùng cho docs kit ban đầu: tooling selftest dùng temp copies, kiểm runtime còn unimplemented và chưa có subsequent plans. Sau khi runtime/plan được triển khai, cập nhật phạm vi QA bằng authorized docs plan; không sửa để che runtime failure. Contract checks là structural assertions cho design seeds, không thay OpenAPI/JSON Schema validator đầy đủ ở T03. Mermaid source có SVG/PNG milestone view; exact DAG49 task ở contracts/tasks.json, không suy dependencies chi tiết từ milestone view.
