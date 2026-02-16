#!/bin/bash
# Deploy Catalog Beer API to server

# Warn if there are uncommitted changes
if ! git diff --quiet HEAD 2>/dev/null; then
	echo "WARNING: You have uncommitted changes."
	git status --short
	echo ""
	read -p "Deploy anyway? (y/n): " confirm
	if [[ $confirm != "y" ]]; then
		echo "Aborted."
		exit 0
	fi
fi

echo "Deploy to which environment?"
echo "  1) Staging"
echo "  2) Production"
read -p "Select (1 or 2): " choice

case $choice in
	1)
		HOST="172.236.249.199"
		DEST="api-staging.catalog.beer"
		echo "Deploying to Staging..."
		;;
	2)
		HOST="172.233.129.106"
		DEST="api.catalog.beer"
		read -p "Are you sure you want to deploy to Production? (y/n): " confirm
		if [[ $confirm != "y" ]]; then
			echo "Aborted."
			exit 0
		fi
		echo "Deploying to Production..."
		;;
	*)
		echo "Invalid selection. Aborted."
		exit 1
		;;
esac

REMOTE="michael@$HOST"
REMOTE_PATH="/var/www/html/$DEST/public_html"
SOCKET="/tmp/deploy-ssh-$$"

# Open a shared SSH connection to avoid multiple password prompts
ssh -fNM -S "$SOCKET" "$REMOTE"
trap 'ssh -S "$SOCKET" -O exit "$REMOTE" 2>/dev/null' EXIT

rsync -avzO --no-perms --delete \
	-e "ssh -S '$SOCKET'" \
	--exclude '.git' \
	--exclude '.claude' \
	--exclude '.nova' \
	--exclude '.gitignore' \
	--exclude '.gitattributes' \
	--exclude '.DS_Store' \
	--exclude 'CLAUDE.md' \
	--exclude 'deploy.sh' \
	--exclude '*.sql' \
	--exclude 'common/' \
	--exclude 'algolia/test.php' \
	--exclude 'README.md' \
	./ "$REMOTE:$REMOTE_PATH/"

# Set ownership and permissions so Apache can read/serve and michael can deploy
ssh -S "$SOCKET" -t "$REMOTE" "sudo chown -R www-data:developers $REMOTE_PATH/ && sudo find $REMOTE_PATH/ -type d -exec chmod 2775 {} \; && sudo find $REMOTE_PATH/ -type f -exec chmod 664 {} \;"

echo "Deploy to $DEST complete."
