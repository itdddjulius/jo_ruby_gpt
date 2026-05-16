# USER-GUIDE - Bash Creation Commands for RUBY-GPT

This guide shows the full bash workflow to create, run, inspect, and package the RUBY-GPT flat file chatbot project.

## 1. Create the project folder

```bash
mkdir -p ruby-gpt-flatfiles
cd ruby-gpt-flatfiles
mkdir -p api storage/requests storage/answers storage/logs assets
```

## 2. Create the main HTML5/PHP page

```bash
cat > index.php <<'PHP'
# Paste the provided index.php code here.
PHP
```

## 3. Create the PHP search API

```bash
cat > api/search.php <<'PHP'
# Paste the provided api/search.php code here.
PHP
```

## 4. Create the PHP history API

```bash
cat > api/history.php <<'PHP'
# Paste the provided api/history.php code here.
PHP
```

## 5. Create the Dockerfile

```bash
cat > Dockerfile <<'DOCKER'
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libxml2-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install dom curl \
    && docker-php-ext-enable dom curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
RUN mkdir -p /var/www/html/storage/requests /var/www/html/storage/answers /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage

EXPOSE 80
DOCKER
```

## 6. Create docker-compose.yml

```bash
cat > docker-compose.yml <<'YAML'
services:
  ruby-gpt:
    build: .
    container_name: ruby-gpt-flatfiles
    ports:
      - "8080:80"
    volumes:
      - ./storage:/var/www/html/storage
    restart: unless-stopped
YAML
```

## 7. Create .dockerignore

```bash
cat > .dockerignore <<'EOF'
.git
*.zip
storage/**/*.json
storage/**/*.txt
storage/**/*.log
EOF
```

## 8. Set storage permissions

```bash
chmod -R 775 storage
```

## 9. Build and run the project

```bash
docker compose up --build
```

## 10. Open RUBY-GPT in the browser

```bash
open http://localhost:8080
```

On Linux use:

```bash
xdg-open http://localhost:8080
```

On Windows PowerShell use:

```powershell
start http://localhost:8080
```

## 11. Test the search API with curl

```bash
curl -X POST http://localhost:8080/api/search.php \
  -H 'Content-Type: application/json' \
  -d '{
    "INPUT_CHAT":"Explain Ruby on Rails Active Storage",
    "GPT_ENGINE":"CHATGPT",
    "GPT_MODEL":"gpt-4.1",
    "COMPLEXITY":"LEVEL"
  }'
```

## 12. View saved flat files

```bash
find storage -type f -maxdepth 3
```

View requests:

```bash
ls -lah storage/requests
cat storage/requests/*.json
```

View answers:

```bash
ls -lah storage/answers
cat storage/answers/*.txt
```

View errors:

```bash
ls -lah storage/logs
cat storage/logs/*.log
```

## 13. Stop the application

```bash
docker compose down
```

## 14. Rebuild from scratch

```bash
docker compose down --volumes --remove-orphans
docker compose build --no-cache
docker compose up
```

## 15. Package the project into a zip

```bash
cd ..
zip -r ruby-gpt-flatfiles.zip ruby-gpt-flatfiles \
  -x 'ruby-gpt-flatfiles/storage/**/*.json' \
  -x 'ruby-gpt-flatfiles/storage/**/*.txt' \
  -x 'ruby-gpt-flatfiles/storage/**/*.log'
```

## 16. Production hardening checklist

```bash
# Add rate limiting
# Add CSRF protection
# Add request authentication
# Add URL denylist for localhost/private IP ranges
# Add cache for search results
# Add robust HTML readability extraction
# Add multi-result citations
# Add real provider API keys for OpenAI, Claude, Gemini, Groq
```
