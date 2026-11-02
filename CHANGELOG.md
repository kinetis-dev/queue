# Changelog

Notable changes to `kinetis/queue`, newest first.

## 1.9.0

### Added

- `JobSucceeded`, `JobReleased`, `JobFailedPermanently` and `JobSettlementLost` carry `durationMs`, the wall-clock time of the job's handler in this worker, in milliseconds.

### Changed

- **Breaking:** `durationMs` is a required constructor argument of those four events, last on all but `JobSettlementLost`, where it precedes `$failure`. This only matters to code that constructs these events itself; `QueueWorker` is the only constructor in the framework.
