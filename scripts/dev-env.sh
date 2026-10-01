# Development environment for Maguari. Load it in each new terminal, from
# anywhere in the repository:
#
#   source scripts/dev-env.sh
#
# It points the server at the development files under server/var/ and the
# client at client/var/ (both ignored by Git), and makes the server use your
# gcloud Application Default Credentials. These variables are for development
# only (design sections 5.6, 8 and 9).

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    echo "Load this file with source, so the variables stay in your shell:" >&2
    echo "  source ${0}" >&2
    exit 1
fi

maguari_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

export MAGUARI_DATABASE="$maguari_root/server/var/dev.sqlite"
export MAGUARI_GCP_CREDENTIALS=application-default
export MAGUARI_SECRET_KEY_FILE="$maguari_root/server/var/secret.key"
export MAGUARI_CLIENT_DIR="$maguari_root/client/var"

echo "Maguari development environment:"
echo "  MAGUARI_DATABASE=$MAGUARI_DATABASE"
echo "  MAGUARI_GCP_CREDENTIALS=$MAGUARI_GCP_CREDENTIALS"
echo "  MAGUARI_SECRET_KEY_FILE=$MAGUARI_SECRET_KEY_FILE"
echo "  MAGUARI_CLIENT_DIR=$MAGUARI_CLIENT_DIR"

unset maguari_root
