#!/usr/bin/env bash
# Verifica que la API externa de Odoo responda con las credenciales actuales.
# Uso (desde cualquier directorio):
#   bash scripts/verify.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

ENV_FILE=""
for candidate in .env.local .env; do
  if [[ -f "${candidate}" ]]; then
    ENV_FILE="${candidate}"
    break
  fi
done
if [[ -z "${ENV_FILE}" ]]; then
  echo "Falta .env.local o .env. Copia .env.local.example y rellena ODOO_API_KEY." >&2
  exit 1
fi
echo "Usando ${ENV_FILE}"

# shellcheck disable=SC1091
set -a
. "./${ENV_FILE}"
set +a

: "${ODOO_URL:?ODOO_URL no definido}"
: "${ODOO_DB:?ODOO_DB no definido}"
: "${ODOO_LOGIN:?ODOO_LOGIN no definido}"
: "${ODOO_API_KEY:?ODOO_API_KEY no definido}"

echo "==> 1) Authenticate en ${ODOO_URL}/jsonrpc (db=${ODOO_DB}, login=${ODOO_LOGIN})"

AUTH_PAYLOAD=$(cat <<JSON
{
  "jsonrpc": "2.0",
  "method": "call",
  "params": {
    "service": "common",
    "method": "authenticate",
    "args": ["${ODOO_DB}", "${ODOO_LOGIN}", "${ODOO_API_KEY}", {}]
  },
  "id": 1
}
JSON
)

AUTH_RESPONSE=$(curl -sS -X POST "${ODOO_URL}/jsonrpc" \
  -H 'Content-Type: application/json' \
  -d "${AUTH_PAYLOAD}")

echo "Respuesta autenticación:"
echo "${AUTH_RESPONSE}"
echo

# Extrae uid si vino un result numerico
UID_VAL=$(printf '%s' "${AUTH_RESPONSE}" | sed -n 's/.*"result"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p')

if [[ -z "${UID_VAL}" ]]; then
  echo "No se obtuvo uid. Revisa el mensaje de error de arriba." >&2
  echo "Pistas comunes:"
  echo "  - 'Access Denied' o 'wrong login/password' -> API key mal copiada o login incorrecto"
  echo "  - Error mencionando 'subscription' / 'plan' -> tu plan no incluye API externa (necesitas Custom)"
  echo "  - 'database not found' -> ODOO_DB no es el correcto (prueba sin la palabra DB literal; mira el subdominio)"
  exit 2
fi

echo "==> uid obtenido: ${UID_VAL}"
echo

echo "==> 2) Lectura de res.users (self) via execute_kw"

READ_PAYLOAD=$(cat <<JSON
{
  "jsonrpc": "2.0",
  "method": "call",
  "params": {
    "service": "object",
    "method": "execute_kw",
    "args": [
      "${ODOO_DB}",
      ${UID_VAL},
      "${ODOO_API_KEY}",
      "res.users",
      "read",
      [[${UID_VAL}]],
      {"fields": ["name", "login", "company_id", "lang", "tz"]}
    ]
  },
  "id": 2
}
JSON
)

READ_RESPONSE=$(curl -sS -X POST "${ODOO_URL}/jsonrpc" \
  -H 'Content-Type: application/json' \
  -d "${READ_PAYLOAD}")

echo "Respuesta lectura:"
echo "${READ_RESPONSE}"
echo
echo "Si arriba ves un objeto con name/login/company_id => conexion OK, seguimos con Laravel."
