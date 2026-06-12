# Local Development Setup - Caddy + Coraza WAF

## Prerequisites

- Docker & Docker Compose installed
- Caddy installed locally (v2.7.0+)
- Coraza module enabled in Caddy (or standard Caddy for basic reverse proxy)

## Quick Start

### 1. Start SIAKAD Docker Container

```bash
cd /home/ubuntu/Documents/projects/siakad

# Start only the SIAKAD app (without Caddy in Docker)
docker-compose up -d siakad

# Verify it's running
docker-compose ps
docker logs siakad-app

# Test health check
curl http://localhost:8080/index.php?page=health
```

### 2. Configure Local Caddy

#### Option A: Using Caddyfile (Recommended)

```bash
# Copy local Caddyfile to Caddy config
sudo cp Caddyfile.local /etc/caddy/Caddyfile

# Copy Coraza rules (if using Caddy with Coraza module)
sudo cp coraza-rules.conf.local /etc/caddy/coraza-rules.conf

# Set proper permissions
sudo chown root:root /etc/caddy/Caddyfile
sudo chown root:root /etc/caddy/coraza-rules.conf
sudo chmod 644 /etc/caddy/Caddyfile
sudo chmod 644 /etc/caddy/coraza-rules.conf

# Create log directory
sudo mkdir -p /var/log/caddy
sudo chown -R caddy:caddy /var/log/caddy

# Reload Caddy configuration
sudo systemctl restart caddy

# Verify Caddy is running
sudo systemctl status caddy
```

#### Option B: Manual Caddy Configuration

If you prefer to configure Caddy manually:

```bash
# Edit Caddy configuration directly
sudo nano /etc/caddy/Caddyfile
```

Add this to your Caddyfile:

```
http://localhost:80, https://localhost:443 {
	tls internal
	
	# Security Headers
	header Strict-Transport-Security "max-age=31536000"
	header X-Content-Type-Options "nosniff"
	header X-Frame-Options "SAMEORIGIN"
	
	# Compress responses
	encode gzip
	
	# Reverse proxy to Docker container
	reverse_proxy localhost:8080 {
		health /index.php?page=health
		health_interval 30s
		health_timeout 5s
		
		header_up X-Forwarded-For {http.request.remote}
		header_up X-Forwarded-Proto {http.request.scheme}
		header_up X-Forwarded-Host {http.request.host}
		header_up X-Real-IP {http.request.remote}
	}
	
	# Logging
	log {
		output file /var/log/caddy/siakad-access.log {
			roll_size 100mb
			roll_keep 3
		}
		format json
	}
}
```

Then reload:

```bash
sudo systemctl reload caddy
```

### 3. Verify Setup

```bash
# Check Caddy status
sudo systemctl status caddy
sudo caddy list-modules  # View installed modules

# Test reverse proxy
curl -k https://localhost/

# Test SIAKAD app
curl -k https://localhost/index.php?page=health

# Check logs
sudo tail -f /var/log/caddy/siakad-access.log
sudo tail -f /var/log/caddy/coraza-audit.log  # If Coraza enabled
```

## Caddy Management Commands

### Start/Stop/Restart

```bash
# Start Caddy
sudo systemctl start caddy

# Stop Caddy
sudo systemctl stop caddy

# Restart Caddy (reload config)
sudo systemctl restart caddy

# Reload config without restart
sudo systemctl reload caddy

# Check status
sudo systemctl status caddy
```

### View Logs

```bash
# Real-time access logs
sudo tail -f /var/log/caddy/siakad-access.log

# JSON format (for processing)
sudo tail -f /var/log/caddy/siakad-access.log | jq .

# WAF audit logs (if Coraza enabled)
sudo tail -f /var/log/caddy/coraza-audit.log

# Caddy service logs
sudo journalctl -u caddy -f

# Historical logs
sudo tail -100 /var/log/caddy/siakad-access.log
```

### Enable Coraza Module (If Not Already Enabled)

Caddy doesn't ship with Coraza by default. You have options:

#### Option 1: Install Caddy with Coraza (Recommended)

```bash
# Using Caddy's download tool with modules
wget https://github.com/caddyserver/caddy/releases/download/v2.7.4/caddy_linux_amd64.tar.gz

# Or use Caddy's xcaddy to build with Coraza
go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest

xcaddy build \
  --with github.com/corazaio/caddy-coraza@latest

# Move binary
sudo cp caddy /usr/bin/caddy
sudo systemctl restart caddy

# Verify Coraza is available
caddy list-modules | grep coraza
```

#### Option 2: Use Standard Caddy (Without Coraza)

Standard Caddy provides excellent reverse proxy and security headers. Coraza provides advanced WAF rules but isn't required for development.

```bash
# Standard Caddy is sufficient for development
# Just use the Caddyfile without Coraza rules
sudo systemctl restart caddy
```

## Access Application

### Via Browser

- **HTTPS (with self-signed cert):** https://localhost
- **Login:** Username: `admin`, Password: `admin123`
- **Health Check:** https://localhost/index.php?page=health

### Via Command Line

```bash
# Login page
curl -k https://localhost/?page=login

# Dashboard
curl -k https://localhost/?page=dashboard

# Health check
curl -k https://localhost/?page=health

# Landing images (admin panel)
curl -k https://localhost/?page=landing_images
```

## Troubleshooting

### Caddy won't start

```bash
# Check configuration syntax
caddy validate --config /etc/caddy/Caddyfile

# View detailed error
sudo journalctl -u caddy -n 50

# Try running manually to see errors
sudo caddy run --config /etc/caddy/Caddyfile
```

### Port already in use

```bash
# Check what's using port 80/443
sudo lsof -i :80
sudo lsof -i :443

# Kill process if needed
sudo kill -9 <PID>

# Or change Caddy ports in Caddyfile
# http://localhost:8000, https://localhost:8443
```

### Docker container not responding

```bash
# Check container status
docker-compose ps
docker logs siakad-app

# Restart container
docker-compose restart siakad

# Check connectivity
docker-compose exec siakad curl http://localhost/index.php?page=health
```

### SSL certificate warnings

Self-signed certificates will show warnings. This is normal for development.

To suppress in curl:
```bash
curl -k https://localhost/  # -k ignores certificate validation
```

### Reverse proxy not working

```bash
# Verify Caddy can reach Docker container
curl http://localhost:8080/index.php?page=health

# Check Caddy logs
sudo tail -50 /var/log/caddy/siakad-access.log

# Verify Docker network
docker network ls
docker network inspect siakad_default
```

## Performance Monitoring

### Check Container Resource Usage

```bash
# Real-time stats
docker stats siakad-app

# Detailed stats
docker stats --no-stream

# Memory/CPU limits
docker inspect siakad-app | grep -A 5 "Memory\|Cpu"
```

### Check Caddy Performance

```bash
# Caddy admin API (local)
curl http://localhost:2019/config/

# View current config
curl http://localhost:2019/config/apps/http/

# Check metrics (if enabled)
curl http://localhost:2019/metrics
```

## Development Workflow

### Making Changes to SIAKAD

```bash
# Edit code locally (files are in ./index.php etc.)
nano index.php

# Changes are reflected immediately in Docker container
# (if volumes are properly mounted)

# Restart Docker to ensure all changes are applied
docker-compose restart siakad
```

### Modifying Caddy Configuration

```bash
# Edit Caddyfile
sudo nano /etc/caddy/Caddyfile

# Validate syntax
caddy validate --config /etc/caddy/Caddyfile

# Reload (if valid)
sudo systemctl reload caddy

# Or restart if reload doesn't work
sudo systemctl restart caddy

# Check new config
sudo tail -f /var/log/caddy/siakad-access.log
```

### Adding WAF Rules

```bash
# Edit Coraza rules
sudo nano /etc/caddy/coraza-rules.conf

# Restart Caddy
sudo systemctl restart caddy

# Monitor for blocked requests
sudo tail -f /var/log/caddy/coraza-audit.log
```

## Cleanup

### Stop All Services

```bash
# Stop Docker containers
docker-compose down

# Stop Caddy
sudo systemctl stop caddy

# Remove volumes (careful - deletes data!)
docker-compose down -v
```

### Remove Configuration

```bash
# Remove Caddy config
sudo rm /etc/caddy/Caddyfile
sudo rm /etc/caddy/coraza-rules.conf

# Remove logs
sudo rm -rf /var/log/caddy/

# Restart Caddy
sudo systemctl restart caddy
```

## Testing WAF Rules

### Test SQL Injection Blocking

```bash
# Should be blocked by WAF
curl -k "https://localhost/?username=admin' OR '1'='1"

# Check logs
sudo tail /var/log/caddy/coraza-audit.log | grep "SQL Injection"
```

### Test XSS Blocking

```bash
# Should be blocked
curl -k "https://localhost/?name=<script>alert('xss')</script>"

# Check logs
sudo tail /var/log/caddy/coraza-audit.log | grep "XSS"
```

### Test File Upload Protection

```bash
# Try uploading non-image file (should be blocked)
curl -k -F "image=@malicious.php" https://localhost/index.php?page=landing_images

# Check logs
sudo tail /var/log/caddy/coraza-audit.log | grep "FILE_UPLOAD"
```

## Next Steps

1. **Development:** Make changes to SIAKAD code
2. **Testing:** Access via https://localhost and test functionality
3. **WAF Monitoring:** Check `/var/log/caddy/coraza-audit.log` for blocked requests
4. **Deployment:** When ready, deploy to production using docker-compose on server

---

**Setup Date:** 2026-06-12
**SIAKAD Port:** 8080 (Docker)
**Caddy Reverse Proxy:** localhost:80/443
**WAF Engine:** Coraza (optional)
