#!/bin/bash
# CORS Fix Script for cPanel/Laravel
# Run this on your server via SSH

set -e

echo "========================================="
echo "CORS FIX SCRIPT FOR cPanel/Laravel"
echo "========================================="
echo

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored messages
print_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -eq 0 ]; then 
    print_warn "Running as root. Be careful with file permissions."
fi

# Get current directory
CURRENT_DIR=$(pwd)
print_info "Current directory: $CURRENT_DIR"

# Check if we're in a Laravel project
if [ ! -f "artisan" ] && [ ! -f "public/index.php" ]; then
    print_warn "Laravel files not found in current directory."
    print_warn "Make sure you're in the Laravel project root or public directory."
    read -p "Continue anyway? (y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Determine Laravel directory structure
if [ -f "artisan" ]; then
    # We're in Laravel root
    LARAVEL_ROOT="$CURRENT_DIR"
    PUBLIC_DIR="$CURRENT_DIR/public"
    print_info "Detected Laravel root: $LARAVEL_ROOT"
elif [ -f "../artisan" ]; then
    # We're in public directory
    LARAVEL_ROOT="$CURRENT_DIR/.."
    PUBLIC_DIR="$CURRENT_DIR"
    print_info "Detected public directory. Laravel root: $LARAVEL_ROOT"
else
    # Can't determine structure
    print_warn "Cannot determine Laravel structure."
    PUBLIC_DIR="$CURRENT_DIR"
    LARAVEL_ROOT="$CURRENT_DIR"
fi

print_info "Public directory: $PUBLIC_DIR"
print_info "Laravel root: $LARAVEL_ROOT"

# Create backup of existing .htaccess
if [ -f "$PUBLIC_DIR/.htaccess" ]; then
    BACKUP_FILE="$PUBLIC_DIR/.htaccess.backup.$(date +%Y%m%d_%H%M%S)"
    cp "$PUBLIC_DIR/.htaccess" "$BACKUP_FILE"
    print_info "Backup created: $BACKUP_FILE"
fi

# Test different .htaccess configurations
echo
print_info "Testing different .htaccess configurations..."

# Option 1: Simple CORS .htaccess
echo
print_info "Option 1: Simple CORS .htaccess"
cat > "$PUBLIC_DIR/.htaccess" << 'EOF'
# SIMPLE CORS .htaccess for cPanel
# Test this first - most basic version

<IfModule mod_rewrite.c>
    RewriteEngine On
    
    # Handle OPTIONS preflight directly in Apache
    RewriteCond %{REQUEST_METHOD} OPTIONS
    RewriteRule ^(.*)$ $1 [R=200,L]
    
    # Standard Laravel rewrites
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
    # CORS headers
    Header always set Access-Control-Allow-Origin "https://documentacion.xpertiaplus.com"
    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin"
    Header always set Access-Control-Allow-Credentials "true"
    Header always set Access-Control-Max-Age "86400"
</IfModule>
EOF
print_info "Simple .htaccess installed. Testing..."

# Test OPTIONS response
print_info "Testing OPTIONS request..."
if command -v curl &> /dev/null; then
    curl -s -I -X OPTIONS https://api.documentacion.xpertiaplus.com/api/login | head -20
else
    print_warn "curl not available for testing"
fi

read -p "Did OPTIONS work? (y/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    # Option 2: Advanced CORS .htaccess
    echo
    print_info "Option 2: Advanced CORS .htaccess"
    cat > "$PUBLIC_DIR/.htaccess" << 'EOF'
# ADVANCED CORS .htaccess for cPanel
# Uses multiple techniques to ensure CORS works

RewriteEngine On

# ========== CORS HEADERS (Apache level) ==========
<IfModule mod_headers.c>
    # Remove any existing CORS headers first
    Header unset Access-Control-Allow-Origin
    Header unset Access-Control-Allow-Methods
    Header unset Access-Control-Allow-Headers
    Header unset Access-Control-Allow-Credentials
    
    # Set CORS headers for ALL responses (including errors)
    Header always set Access-Control-Allow-Origin "https://documentacion.xpertiaplus.com"
    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, X-XSRF-Token, X-CSRF-Token, Accept, Origin"
    Header always set Access-Control-Allow-Credentials "true"
    Header always set Access-Control-Max-Age "86400"
    
    # Special handling for OPTIONS preflight
    <IfModule mod_rewrite.c>
        RewriteCond %{REQUEST_METHOD} OPTIONS
        RewriteRule ^(.*)$ $1 [R=200,E=IS_OPTIONS:1,L]
        
        # Force headers for OPTIONS response
        Header always set Access-Control-Allow-Origin "https://documentacion.xpertiaplus.com" env=IS_OPTIONS
    </IfModule>
</IfModule>

# ========== LARAVEL REWRITES ==========
<IfModule mod_rewrite.c>
    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    
    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule ^ - [E=HTTP_X_XSRF_TOKEN:%{HTTP:x-xsrf-token}]
    
    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]
    
    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# ========== SECURITY HEADERS ==========
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
</IfModule>

# Prevent directory listing
Options -Indexes
EOF
    
    print_info "Advanced .htaccess installed. Testing..."
    
    # Test again
    if command -v curl &> /dev/null; then
        curl -s -I -X OPTIONS https://api.documentacion.xpertiaplus.com/api/login | head -20
    fi
    
    read -p "Did OPTIONS work now? (y/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        # Option 3: No-rewrite .htaccess (headers only)
        echo
        print_info "Option 3: Headers-only .htaccess (no rewrite)"
        cat > "$PUBLIC_DIR/.htaccess" << 'EOF'
# HEADERS-ONLY CORS .htaccess
# Use if mod_rewrite is not available

<IfModule mod_headers.c>
    # CORS headers for all responses
    Header always set Access-Control-Allow-Origin "https://documentacion.xpertiaplus.com"
    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin"
    Header always set Access-Control-Allow-Credentials "true"
    Header always set Access-Control-Max-Age "86400"
    
    # For OPTIONS requests, Apache needs to return 200
    RewriteEngine On
    RewriteCond %{REQUEST_METHOD} OPTIONS
    RewriteRule .* - [R=200,L]
</IfModule>

# Basic Laravel rewrite (may fail without mod_rewrite)
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
EOF
        
        print_info "Headers-only .htaccess installed."
    fi
fi

# Copy test files to public directory
echo
print_info "Copying test files to public directory..."

# Copy test-cors.php
if [ -f "test-cors.php" ]; then
    cp test-cors.php "$PUBLIC_DIR/"
    print_info "test-cors.php copied to $PUBLIC_DIR"
fi

# Copy server-info.php  
if [ -f "server-info.php" ]; then
    cp server-info.php "$PUBLIC_DIR/"
    print_info "server-info.php copied to $PUBLIC_DIR"
fi

# Update Laravel CORS config
echo
print_info "Updating Laravel CORS configuration..."
CORS_CONFIG="$LARAVEL_ROOT/config/cors.php"
if [ -f "$CORS_CONFIG" ]; then
    # Backup original
    cp "$CORS_CONFIG" "$CORS_CONFIG.backup.$(date +%Y%m%d_%H%M%S)"
    
    # Update allowed origins
    sed -i "s/'https:\/\/documentacion\.xpertiaplus\.com',/'https:\/\/documentacion.xpertiaplus.com',\n        'https:\/\/www.documentacion.xpertiaplus.com',/" "$CORS_CONFIG"
    print_info "CORS config updated with www subdomain"
else
    print_warn "CORS config not found at $CORS_CONFIG"
fi

# Update routes/api.php for OPTIONS handling
echo
print_info "Checking Laravel routes for OPTIONS handling..."
API_ROUTES="$LARAVEL_ROOT/routes/api.php"
if [ -f "$API_ROUTES" ]; then
    # Check if OPTIONS route already exists
    if ! grep -q "Route::options" "$API_ROUTES"; then
        # Add OPTIONS route at the beginning
        sed -i "/^<?php/a \\\n// Handle OPTIONS preflight requests\\nRoute::options('/{any}', function () {\\n    return response()->make('', 204)\\n        ->header('Access-Control-Allow-Origin', 'https://documentacion.xpertiaplus.com')\\n        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')\\n        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept, Origin')\\n        ->header('Access-Control-Allow-Credentials', 'true')\\n        ->header('Access-Control-Max-Age', '86400');\\n})->where('any', '.*');" "$API_ROUTES"
        print_info "OPTIONS route added to api.php"
    else
        print_info "OPTIONS route already exists in api.php"
    fi
else
    print_warn "API routes file not found at $API_ROUTES"
fi

# Clear Laravel cache
echo
print_info "Clearing Laravel cache..."
if [ -f "$LARAVEL_ROOT/artisan" ]; then
    cd "$LARAVEL_ROOT"
    php artisan config:clear 2>/dev/null || print_warn "Could not clear config cache"
    php artisan cache:clear 2>/dev/null || print_warn "Could not clear cache"
    php artisan route:clear 2>/dev/null || print_warn "Could not clear route cache"
    cd "$CURRENT_DIR"
    print_info "Laravel cache cleared"
fi

# Set correct permissions
echo
print_info "Setting file permissions..."
chmod 644 "$PUBLIC_DIR/.htaccess" 2>/dev/null || print_warn "Could not set .htaccess permissions"
chmod 644 "$PUBLIC_DIR/test-cors.php" 2>/dev/null || true
chmod 644 "$PUBLIC_DIR/server-info.php" 2>/dev/null || true

# Final instructions
echo
echo "========================================="
echo "INSTALLATION COMPLETE"
echo "========================================="
echo
echo "Next steps:"
echo "1. Test OPTIONS request:"
echo "   curl -I -X OPTIONS https://api.documentacion.xpertiaplus.com/api/login"
echo
echo "2. Check server info:"
echo "   https://api.documentacion.xpertiaplus.com/server-info.php"
echo
echo "3. Test CORS handling:"
echo "   https://api.documentacion.xpertiaplus.com/test-cors.php"
echo
echo "4. If still not working:"
echo "   - Check cPanel → Apache Modules (enable rewrite_module, headers_module)"
echo "   - Check cPanel error logs"
echo "   - Contact hosting support if modules can't be enabled"
echo
echo "5. Once working, you can remove test files:"
echo "   rm $PUBLIC_DIR/test-cors.php $PUBLIC_DIR/server-info.php"
echo
echo "Backup files created:"
if [ -f "$BACKUP_FILE" ]; then
    echo "   - $BACKUP_FILE"
fi
if [ -f "$CORS_CONFIG.backup"* ]; then
    ls -1 "$CORS_CONFIG.backup"* 2>/dev/null | head -1
fi
echo
echo "========================================="