# Development environment for Maguari. Load it in each new terminal, from
# anywhere in the repository:
#
#   source scripts/dev-env.sh
#
# It points the server at the development files under server/var/ and the
# client at client/var/ (both ignored by Git), including the certificate
# scanner's input and output and the seed config file, and makes the server
# use your gcloud Application Default Credentials. These variables are for
# development only (design sections 5.6, 6.1.1, 8, 9 and 11.5.1).

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "Load this file with source, so the variables stay in your shell:" >&2
    echo "  source ${0}" >&2
    exit 1
fi

maguari_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

export MAGUARI_DATABASE="$maguari_root/server/var/dev.sqlite"
export MAGUARI_GCP_CREDENTIALS=application-default
export MAGUARI_SECRET_KEY_FILE="$maguari_root/server/var/secret.key"
export MAGUARI_SEED_FILE="$maguari_root/server/var/seed.ini"
export MAGUARI_CLIENT_DIR="$maguari_root/client/var"
export MAGUARI_CERTIFICATES_FILE="$maguari_root/client/var/certificates.json"
export MAGUARI_LETSENCRYPT_DIR="$maguari_root/client/var/letsencrypt"

echo "Maguari development environment:"
echo "  MAGUARI_DATABASE=$MAGUARI_DATABASE"
echo "  MAGUARI_GCP_CREDENTIALS=$MAGUARI_GCP_CREDENTIALS"
echo "  MAGUARI_SECRET_KEY_FILE=$MAGUARI_SECRET_KEY_FILE"
echo "  MAGUARI_SEED_FILE=$MAGUARI_SEED_FILE"
echo "  MAGUARI_CLIENT_DIR=$MAGUARI_CLIENT_DIR"
echo "  MAGUARI_CERTIFICATES_FILE=$MAGUARI_CERTIFICATES_FILE"
echo "  MAGUARI_LETSENCRYPT_DIR=$MAGUARI_LETSENCRYPT_DIR"

unset maguari_root
