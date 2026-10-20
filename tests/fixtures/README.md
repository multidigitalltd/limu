# Frozen cache regression fixture

`crm-0.1.1.min.js` is copied byte for byte from
`limu-crm/assets/crm.min.js` in the published `limu-crm-0.1.1.zip`.
Only this script is required to exercise an actual cached client against the
current REST contract; the historical plugin archive is not a test dependency.

- Script: 47,202 bytes.
- Script SHA-256: `b8a8c68152f4ceeff94e27a51e24c10f676c91f8474cd0d1c3d36868d2bf644f`.
- Original archive SHA-256: `2062c958d21fbbfa2f70664efd7c04a83d9da41aa2be839ff7c4be8001ef7e42`.

Keep this fixture unchanged. `tests/settings-cache.cjs` verifies its checksum
before use and serves it only through an intercepted local browser request.
It intentionally preserves historical client behavior, including removed UI.
It is never enqueued by the plugin and is outside the installed plugin package.
