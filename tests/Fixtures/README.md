# Test fixtures

- `foreign-signing-key.pem`: an RSA key that is **not** the platform signing key. Used only to prove
  that tokens signed by any other key are rejected. It protects nothing; never use it outside tests.
