# Changelog

## 0.1.0

First release, with the same surface as the Node.js and Python libraries: uploads in parts (streamed from a path or an open stream) and from links, `dub()`, dubs (create, retrieve, list, `listAll`, cancel, files, transcript, subtitles, quality, `waitUntilDone`), early-access `dubs->requests`, `dubs->lines` and `runs`, languages, usage and `usage->report()`, webhook endpoints (including `resendDelivery`), webhook signature checks, retries with backoff that honour `Retry-After`, and idempotency keys.

Laravel support in the same package: service provider, publishable config, `Reedapt` facade, `reedapt.webhook` middleware, a webhook controller and a Laravel event per webhook type.
