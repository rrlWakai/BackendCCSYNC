# Aiven CA certificate

Download the CA certificate for the Aiven MySQL service and save its PEM contents as
`backend/certs/aiven-ca.pem` before building the Render image. The image's `COPY . .`
instruction includes this directory, and `backend/.dockerignore` does not exclude it.

Set Render's `MYSQL_ATTR_SSL_CA` to `/var/www/html/certs/aiven-ca.pem` and keep
`MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true` unless Aiven support explicitly directs
otherwise. A CA certificate is public trust material, but do not place database
credentials or private keys in this directory.
