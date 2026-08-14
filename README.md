# Survos Depot Bundle

StoryStation scan-job orchestration for **depot** appliances — the scanner-wired edge machines
that run continuous ADF (automatic document feeder) batch scanning, hand images off to `ai-tools`
for crop/deskew, and push the results to **ssai** (the central hub). Also provides the
`Realtime/` Redis Pub/Sub event bus shared between depot and ssai.

Graduated from depot's own `packages/depot-bundle` path package. `src/Command/` and
`src/Controller/` are depot-side (scan-appliance orchestration); `src/Realtime/` is consumed by
both depot and ssai.

## Installation

This is a **private** package — not on Packagist. Add a VCS repository pointing at the GitHub
repo instead of a plain `composer require`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/survos/depot-bundle" }
    ]
}
```

```bash
composer require survos/depot-bundle
```

Composer needs GitHub auth to clone a private repo — an SSH deploy key, or a token via
`COMPOSER_AUTH`/`auth.json` (`composer config github-oauth.github.com <token>`) — on whatever
machine runs `composer install`.

## What it does

- **`depot:scan:trigger`** — trigger a duplex batch scan (envelope/accession-lot `intakeCode`,
  incrementing accession numbers) and hand the images off to `ssai` or `mediary`.
- **`depot:poll-scan-jobs`** — the depot↔ssai communications link: depot polls ssai for a
  StoryStation scan job, then runs continuous ADF batches (reload the feeder, it keeps going)
  until ssai marks the job stopped. Depot never needs to be reachable *from* ssai — it only ever
  polls out. Logs to the `scan_jobs` channel (`var/log/scan-jobs.log`).
- **Controllers** (`ScanFileController`, `ScanJobTriggerController`, `ScanIngestController`,
  `RetryCropController`, `ScanDeleteController`, `StatusController`) — the depot-side HTTP
  surface for scan file/job management.
- **`Realtime/`** — an ephemeral Redis Pub/Sub event bus (never authoritative) for pushing events
  like `asset.ocr.completed` between depot and ssai in near-real-time. Falls back to a
  `NullEventPublisher` when disabled or no DSN is configured.

## Configuration

```yaml
survos_depot:
    events:
        enabled: true
        dsn: 'redis://127.0.0.1:6379'   # empty disables publishing (NullEventPublisher)
        channel: 'depot.events'
        node_id: 'depot-rapp'           # identifies this node in every event envelope
```

`ssai`, as the central hub, only needs the `Realtime/` event bus — set
`routes_enabled: false` in its own `config/packages/survos_depot.yaml`, since this bundle's
`Controller/` routes (e.g. `POST /internal/scans`) collide with ssai's own
`App\Controller\Internal\ScanIngestController` at the same path.

## License

Proprietary.
