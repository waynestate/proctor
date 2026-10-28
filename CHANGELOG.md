# Changelog

## Unreleased

- Rule ids: every finding now carries a stable, namespaced rule id (`proctor/<check-id>` for a check's default
  finding, `proctor/<check-id>/<rule>` for a named rule). It is exposed additively as `rule_id` in the `json`
  format, as `ruleId` in `sarif`, and as `check_name` in `codequality` (previously the check id). The full set of
  44 rule ids is documented in the README; `tests/RuleIdContractTest.php` keeps the table in step with the checks.
- `portability`: PHP files are matched on the syntax tree (nikic/php-parser with names resolved) instead of line by
  line regular expressions. Comments and strings no longer trigger a rule, calls split over lines are caught,
  and `use function ... as ...` aliases resolve. Rules live as data in `Waynestate\Proctor\Code\BannedPatterns`.
- `portability`: a call through a variable (`$f()`) is reported as an info "cannot analyse" note, once per file.
- `portability`: the parse-error warning now names the file ("could not parse src/Foo.php: ...").
- Docs: `BEST_PRACTICES.md` names `Policy::fromFile()` instead of the deleted `JsonFilePolicySource`.
