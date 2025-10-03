#!/bin/bash
set -e

# Run database migrations
echo "Running database migrations..."
php /app/bin/migrate.php

# Execute the original entrypoint with all arguments
exec docker-php-entrypoint "$@"
