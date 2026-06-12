# SIAKAD Docker Deployment Guide

## 🚀 Quick Start

Deploy SIAKAD to `siakad.jawara.cloud` with Caddy reverse proxy and Coraza WAF.

### Prerequisites

- Docker & Docker Compose installed
- Domain `siakad.jawara.cloud` pointing to server
- Server with ports 80, 443 open
- At least 2GB RAM, 10GB storage

### Deployment Steps

#### 1. Clone Repository on Server

```bash
cd /opt
git clone https://github.com/anomalyco/siakad.git
cd siakad
git checkout feature/landing-image-upload  # Or your production branch
```

#### 2. Configure Environment

```bash
# Copy production environment
cp .env.production .env

# Update domain if different
sed -i 's/siakad.jawara.cloud/your-domain.com/g' docker-compose.yml .env.production
sed -i 's/admin@jawara.cloud/your-email@example.com/g' docker-compose.yml .env.production
```

#### 3. Prepare Caddy Configuration Directory

```bash
# The caddy directory is already in the repo
ls -la caddy/
# Expected output:
# Caddyfile
# coraza-rules.conf
```

#### 4. Start Services

```bash
# Build and start all containers
docker-compose up -d

# Check status
docker-compose ps
docker-compose logs -f caddy
docker-compose logs -f siakad
```

#### 5. Verify Deployment

```bash
# Wait 30-60 seconds for Let's Encrypt certificate
sleep 60

# Check HTTPS
curl -I https://siakad.jawara.cloud/

# Check app health
curl https://siakad.jawara.cloud/index.php?page=health

# Check WAF logs
docker-compose exec caddy tail -f /var/log/caddy/waf.log
```

---

## 📋 Configuration Details

### Docker Compose Services

**caddy** - Reverse proxy & WAF
- Image: `caddy:latest`
- Ports: 80 (HTTP), 443 (HTTPS)
- Volumes:
  - `./caddy/Caddyfile` → `/etc/caddy/Caddyfile`
  - `./caddy/coraza-rules.conf` → `/etc/caddy/coraza-rules.conf`
  - `caddy-data` → `/data` (certificates)
  - `caddy-logs` → `/var/log/caddy` (access/WAF logs)

**siakad** - PHP application
- Image: Built from `./Dockerfile`
- Network: Internal only (not exposed)
- Volumes:
  - `siakad-data` → `/var/www/html/data` (SQLite DB)
  - `siakad-logs` → `/var/www/html/logs` (app logs)
  - `siakad-uploads` → `/var/www/html/uploads` (user files)

### Security Features

✅ **SSL/TLS**
- Automatic HTTPS with Let's Encrypt
- Auto-renewal (handled by Caddy)
- HSTS header (force HTTPS)

✅ **WAF (Coraza)**
- SQL injection prevention
- XSS protection
- Path traversal blocking
- File upload validation
- Rate limiting
- Detailed audit logging

✅ **Security Headers**
- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff
- Content-Security-Policy
- Referrer-Policy
- Strict-Transport-Security

✅ **Network Isolation**
- Caddy exposed to internet (port 80, 443)
- SIAKAD app not directly accessible
- Internal bridge network for communication

---

## 📊 Monitoring & Logs

### View Access Logs

```bash
# Real-time access logs
docker-compose exec caddy tail -f /var/log/caddy/access.log

# JSON format for processing
docker-compose exec caddy jq . /var/log/caddy/access.log
```

### View WAF Logs

```bash
# Real-time WAF logs (attacks blocked)
docker-compose exec caddy tail -f /var/log/caddy/waf.log

# Count blocked requests
docker-compose exec caddy grep "403" /var/log/caddy/waf.log | wc -l

# Show recent blocked requests
docker-compose exec caddy tail -50 /var/log/caddy/waf.log | grep "deny"
```

### View Application Logs

```bash
# Real-time app logs
docker-compose logs -f siakad

# App error logs
docker-compose exec siakad tail -f /var/www/html/logs/app.log
```

### Check Health Status

```bash
# All services
docker-compose ps

# Caddy health
docker-compose exec caddy curl -s http://localhost/health

# App health
docker-compose exec siakad curl -s http://localhost/index.php?page=health
```

---

## 🔧 Management Commands

### Start/Stop

```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Restart specific service
docker-compose restart caddy
docker-compose restart siakad
```

### Logs

```bash
# All logs
docker-compose logs

# Follow caddy logs
docker-compose logs -f caddy

# Follow app logs
docker-compose logs -f siakad

# Last 100 lines
docker-compose logs --tail=100
```

### Database

```bash
# Backup SQLite database
docker-compose exec siakad cp /var/www/html/data/siakad.db /var/www/html/data/siakad.db.backup

# List database files
docker-compose exec siakad ls -lh /var/www/html/data/
```

### Volumes

```bash
# List volumes
docker volume ls

# Inspect volume
docker volume inspect siakad_siakad-data

# Clean up unused volumes
docker volume prune
```

---

## 🛡️ WAF Tuning

### View Current Rules

```bash
docker-compose exec caddy cat /etc/caddy/coraza-rules.conf
```

### Adjust Paranoia Level

Edit `caddy/coraza-rules.conf`:

```conf
# Low paranoia (1) - production recommended
# Medium paranoia (2) - stricter
# High paranoia (3) - very strict (may block legitimate traffic)
```

### Whitelist Legitimate Traffic

If legitimate requests are blocked:

```conf
# Add exception rule to coraza-rules.conf
SecRule REQUEST_URI "@beginsWith /your-path" \
    "id:15000,phase:2,pass,nolog"
```

Restart: `docker-compose restart caddy`

### Enable Learning Mode

Temporarily disable blocking to see what would be blocked:

```conf
SecRuleEngine DetectionOnly
```

---

## 🔄 Updates & Maintenance

### Update SIAKAD Code

```bash
# Pull latest changes
git pull origin main

# Rebuild app container
docker-compose down siakad
docker-compose up -d siakad
```

### Update Caddy

```bash
# Pull latest Caddy image
docker pull caddy:latest

# Restart with new image
docker-compose down caddy
docker-compose up -d caddy
```

### Backup Data

```bash
# Backup all volumes
docker run --rm \
  -v siakad_siakad-data:/data \
  -v $(pwd)/backups:/backup \
  alpine tar czf /backup/siakad-data-$(date +%Y%m%d).tar.gz -C /data .
```

---

## 🚨 Troubleshooting

### SSL Certificate Not Generated

```bash
# Check Caddy logs
docker-compose logs caddy

# Verify domain DNS
nslookup siakad.jawara.cloud

# Check port 80/443 accessibility
curl -I http://siakad.jawara.cloud:80
```

### App Not Responding

```bash
# Check app container
docker-compose logs siakad

# Verify health check
docker-compose exec siakad curl http://localhost/index.php?page=health

# Check network connectivity
docker-compose exec caddy ping siakad
```

### WAF Blocking Legitimate Traffic

```bash
# Check recent WAF blocks
docker-compose exec caddy tail -100 /var/log/caddy/waf.log | grep "deny"

# Identify blocked request patterns
# Add exception rule to coraza-rules.conf
# Restart caddy
```

### High CPU/Memory Usage

```bash
# Check resource usage
docker stats

# Reduce log verbosity in Caddyfile
# Lower rate limits
# Reduce buffer sizes
```

---

## 📈 Performance Optimization

### Enable Compression

Already enabled in Caddyfile:
```conf
encode gzip
```

### Configure Caching

Add to Caddyfile:
```conf
header Cache-Control "public, max-age=3600"
```

### Optimize Database

```bash
docker-compose exec siakad sqlite3 /var/www/html/data/siakad.db "VACUUM;"
```

---

## 🔐 Security Best Practices

1. **Keep images updated**
   ```bash
   docker-compose pull
   docker-compose up -d
   ```

2. **Monitor WAF logs regularly**
   - Check for patterns of attacks
   - Adjust rules as needed

3. **Backup data regularly**
   - Automated backups recommended
   - Test restore procedures

4. **Use strong credentials**
   - Change default admin password
   - Use complex CSRF tokens

5. **Review access logs**
   - Monitor unusual traffic
   - Investigate anomalies

---

## 📞 Support

For issues or questions:
- GitHub: https://github.com/anomalyco/siakad
- Check logs: `docker-compose logs`
- WAF rules: Review `caddy/coraza-rules.conf`
- Caddyfile: Review `caddy/Caddyfile`

---

## 📄 Files Reference

```
siakad/
├── docker-compose.yml          # Main Docker composition
├── Dockerfile                  # PHP application build
├── .env.production            # Production environment vars
├── caddy/
│   ├── Caddyfile              # Reverse proxy config
│   └── coraza-rules.conf      # WAF rules
├── index.php                  # Main application
├── uploads/
│   └── landing/              # Landing page images
└── logs/                      # Application logs
```

---

**Deployment Date:** 2026-06-12
**SIAKAD Version:** 1.0 with Landing Page Image Upload
**Caddy Version:** Latest
**Domain:** siakad.jawara.cloud
