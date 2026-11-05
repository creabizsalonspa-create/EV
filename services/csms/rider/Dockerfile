FROM ghcr.io/citrineos/citrineos-server:v2.1.0@sha256:45aedf1e5908ea9aeea29028790bfaeb51a6baf5d7c5fefb94d00e549a7aeece
WORKDIR /usr/local/apps/citrineos/apps/ocpp-server
COPY services/csms/rider/patch-security-actions.mjs ./creabiz-patch-security-actions.mjs
RUN node ./creabiz-patch-security-actions.mjs && rm ./creabiz-patch-security-actions.mjs
