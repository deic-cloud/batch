# Batch

A Nextcloud UI to the **ScienceData GridFactory** batch compute service: submit
jobs from a file or a template, monitor them, inspect stdout/stderr/script/I-O,
and delete them — all authenticated with the user's own X.509 certificate.

- **Nav app "Batch"** — job list + status, New job (edit a template before
  submit), inspect a job, delete, and a Setup panel (generate cert, choose work
  folder, copy job templates).
- No build step: server-rendered PHP + vanilla JS. Own git repo
  (`deic-cloud/batch`).

## How authentication works (read this before touching the connection)

This is the part that was hard-won and previously undocumented. Two hops, two
directions, one identity — **the user's X.509 certificate**.

### 1. Nextcloud → the batch service (submit / list / delete)

`BatchService` connects to the GridFactory server **server-side** with raw curl,
presenting the **user's client certificate + key** (issued and stored by
`files_sharding` `CertificateService`, reached through `CertBridge`;
DN `/CN=<user>/O=sciencedata.dk`).

The connection **must go through the kube-Caddy front** (`batch_api_url =
https://batch.sciencedata.dk/`), **not** the direct ClusterIP (`https://batch/`):

- kube-Caddy terminates the mutual-TLS handshake with the user's cert and sets
  the **`SSL-CLIENT-DN`** header (trusted by the batch server's mod_gacl **only
  from `OnlyFrom=10.2.12.1`**, i.e. Caddy). mod_gacl then sees the authenticated
  DN → the job is **owned by the user** (its `userInfo` is the user's DN) and is
  therefore **deletable / manageable** by that user.
- The direct ClusterIP path sets no such header → the request is **anonymous** →
  jobs are ownerless and management (DELETE) is refused. This is why the default
  is the FQDN (commit `c19d67a`); TLS is verified against the real Let's Encrypt
  cert on that name (`20dfa31`), so the old self-signed pinning hack is gone.

### 2. The batch worker → Nextcloud (staging job input / output)

When a job runs, the worker stages files over WebDAV against the **user's home
server** (`HOME_SERVER_PRIVATE_URL`/`WORK_FOLDER_URL` placeholders resolve to
`https://<silo>/remote.php/dav/files/<uid>/…`). The worker authenticates to the
silo as the **trusted GridFactory daemon** — its certificate DN `/CN=batch` is
listed in the silo's `trusted_dn_header_host_dns`, and it names the user to act
as in the `dn_header` (`SSL-CLIENT-DN`) header. The silo's `X509Backend` then
**impersonates that user** and the worker reads inputs / writes outputs into the
user's own files.

> The silo side of this — the X509Backend, the two headers, the forgery
> invariant — is documented in
> [`files_sharding/docs/x509-auth.md`](../files_sharding/docs/x509-auth.md).
> Keep the two in sync.

## Deployment configuration

`config.php` / `sciencedata.config.php` (system-level):

| Key | Value | Purpose |
|-----|-------|---------|
| `batch_api_url` | `https://batch.sciencedata.dk/` | the GridFactory endpoint — **the kube-Caddy FQDN** (see above). Required. |
| `trusted_dn_header_host_dns` | `/CN=batch,/CN=batch/O=sciencedata.dk` | the daemon DN allowed to impersonate users when staging I/O back to a silo. |
| `dn_header` | `SSL-CLIENT-DN` | header naming the impersonation target. |
| `files_sharding_cert_org` | `sciencedata.dk` | the `O=` in user cert DNs. |

Per user: work folder in `batch`/`batch_folder` (default `/Batch`); the user's
cert lives in `<uid>/files_sharding_ssl/` (generated from the Setup panel).

## GridFactory protocol (what the calls map to)

- **List:** `GET db/jobs/?format=text` (and `db/jobs/<id>/` for one job).
- **Submit:** `MKCOL gridfactory/jobs/<uuid>/` → `PUT` input files → `PUT
  gridfactory/jobs/<uuid>/job` (uploading the job script triggers execution).
  Jobs carry `#GRIDFACTORY -s MY_SSL_DN` so ownership is stamped from the DN.
- **Delete:** `DELETE gridfactory/jobs/<id>` — deletes the **physical job
  directory** over WebDAV; the queuemanager daemon then reconciles the DB row
  away within seconds (the authorised path — matches the CLI `PClean` and the
  old ownCloud app). Verified working 2026-08-31.
- **Request fresh output** (running job): `PUT db/jobs/<id>` with
  `csStatus: running:requestOutput`.
- **Kill** (running job): `PUT db/jobs/<id>` with `csStatus: running:requestKill`
  — stops the process, keeps the directory (output stays inspectable).
- **Files:** stdout / stderr / the job script / declared outputs are fetched by
  URL built server-side from the job id + filename (so a client can't aim the
  user's cert at an arbitrary host).

### Templates

Shell scripts in `<work_folder>/job_templates/` with `#GRIDFACTORY` directives
and placeholders the app substitutes at submit time: `IN_FILE_URL`,
`IN_FOLDER_URL`, `IN_FILENAME`, `IN_BASENAME`, `HOME_SERVER_PRIVATE_URL`,
`WORK_FOLDER_URL`, `-n` (job name), `-o` (output filename + destination),
`-v` (VO restricting who may run). 13 templates ship in `job_templates/`.

## Kill vs. Delete

- **Delete** removes the whole job (`DELETE gridfactory/jobs/<id>` — the
  physical directory).
- **Kill** (running jobs only) sets `csStatus: running:requestKill` (`PUT
  db/jobs/<id>`) — the queuemanager stops the process but **leaves** the job
  and its files, so stdout/stderr stay inspectable (e.g. to see why a job ran
  too long). Mechanism per the CLI `PKill` / `killJob`
  (running→`running:requestKill`, ready→`aborted`); the UI offers Kill only on
  running jobs. An improvement over the old ScienceData app, which had no kill.

## Not yet implemented

- Files **"Process" action** (pick a file → choose a template → submit) —
  deferred to v0.2 (needs the `registerFileAction` webpack build).

## Development

```bash
rsync -av --delete apps/batch/ server:/var/www/nextcloud/apps/batch/
occ upgrade   # after an info.xml version bump
```
