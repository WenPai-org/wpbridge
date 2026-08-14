# Hub-Spoke Stage 3A operations

The Stage 3A state is network-scoped on multisite. An installation cannot be both a Hub and an active Spoke. An unresolved acceptance with a lost response remains visible as a sanitized `uncertain_accept` reconciliation row and blocks every role transition until an administrator uses the recovery endpoint after confirming either that the orphan Hub link was revoked or that the local link is durably active.

The feature flag defaults off for authority-increasing actions. Disabling it does not disable authenticated revoke, local credential wipe, source disable, audit, cron reconciliation, or the explicit uncertain-accept cleanup flow.

Credentials are accepted only in the route-specific `Authorization` header. They are never accepted in query parameters, cookies, or rotation JSON bodies. Active Spoke state blocks new or replacement upstream, source, Bridge Server, vendor, and marketplace credentials on every blog; credential deletion remains available.

Lifecycle audit rows store opaque resource hashes, bounded reason classification, optional reason hashes, and actor classification without raw credentials. The network option retains the newest 500 rows. Expiry emits one row per invitation. Overflow drops only the oldest rows after the replacement state and its lifecycle audit are durably saved; failed expiry/audit writes restore both snapshots.

Remote-revoke receipts contain only the hash of the credential actually presented and are scoped to the same revoked link and compensation route. They remain with the revoked link so a lost `204` can be retried after an extended outage; they never authorize proxy routes.
