# API Installation Guide

This guide will help you install and configure the token-secured API for AXTParts.

## Prerequisites

- Existing AXTParts installation (version 4.0 or higher)
- MySQL/MariaDB database access
- PHP 5.5, 7, or 8 with MySQLi extension
- Apache or compatible web server with mod_rewrite (or equivalent)

## Installation Steps

### 1. Apply Database Migration

The API requires a new `api_tokens` table in your database. Apply the migration using one of these methods:

**Method A: Using MySQL command line**

```bash
cd /opt/axtparts/sql
mysql -u root -p axtparts < axtparts-api-tokens.sql
```

**Method B: Using phpMyAdmin or similar**

1. Open the file `sql/axtparts-api-tokens.sql`
2. Copy the SQL statements
3. Execute them in your database management tool

**Method C: Manual SQL execution**

```sql
USE axtparts;

CREATE TABLE api_tokens (
    tokenid INT UNSIGNED AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    token_name VARCHAR(100),
    uid INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    last_used DATETIME,
    expires_at DATETIME,
    is_active TINYINT(1) DEFAULT 1,
    PRIMARY KEY (tokenid),
    UNIQUE (token),
    INDEX (uid),
    INDEX (is_active)
);
```

### 2. Verify File Permissions

Ensure the API endpoint file has proper permissions:

```bash
# Check that the web server can read the API file
ls -la /path/to/axtparts/axtparts/api-search.php

# Should be readable by the web server user (typically www-data, apache, or httpd)
chmod 644 /path/to/axtparts/axtparts/api-search.php
```

### 3. Configure Web Server (if needed)

**Apache Configuration**

Ensure your web server passes Authorization headers to PHP. Add this to your `.htaccess` or Apache configuration if not already present:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP:Authorization} ^(.*)
    RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
</IfModule>

# Or use SetEnvIf
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

**Nginx Configuration**

For Nginx with PHP-FPM, add to your server block:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    include fastcgi_params;
}
```

### 4. Generate Your First API Token

1. Log in to AXTParts with an admin account
2. Navigate to **Admin** → **API Token Management** (or directly access `frm-api-tokens.php`)
3. Fill in the token generation form:
   - **Token Name**: Give it a descriptive name (e.g., "Mobile App", "Integration Service")
   - **User**: Select the user account the token should be associated with
   - **Expires In**: Set expiration in days (0 = never expires)
4. Click **Generate Token**
5. **IMPORTANT**: Copy the token immediately - it will only be shown once!

### 5. Test the API

Use curl to test your API installation:

```bash
# Replace with your actual API URL and token
API_URL="http://localhost/axtparts/api-search.php"
TOKEN="your_generated_token_here"

# Test basic search
curl -H "Authorization: Bearer $TOKEN" \
     "$API_URL?search=resistor&limit=5"
```

Expected successful response:
```json
{
  "success": true,
  "error": null,
  "data": {
    "query": "resistor",
    "count": 5,
    "limit": 5,
    "offset": 0,
    "results": [...]
  }
}
```

### 6. Use Integration Test Script (Optional)

For comprehensive testing, use the provided test script:

```bash
cd /path/to/axtparts
./test_api_integration.sh http://localhost/axtparts/api-search.php your_token_here
```

## Troubleshooting

### Problem: "Invalid or expired token" error

**Possible causes:**
- Token is incorrect or has been revoked
- Token has expired
- User associated with token is inactive
- User lacks search privileges

**Solution:**
- Verify the token is correct and active in the token management interface
- Check that the user account is active
- Ensure the user has search privileges (TABPRIV_SEARCH)

### Problem: "Database connection failed" error

**Possible causes:**
- Database credentials in config are incorrect
- Database server is not running
- Database user lacks necessary privileges

**Solution:**
- Check database configuration in `config/config-axtparts.php`
- Verify database server is running: `service mysql status`
- Test database connection: `mysql -u axtpartsuser -p axtparts`

### Problem: "Authorization header not received" or always 401

**Possible causes:**
- Web server not configured to pass Authorization headers
- .htaccess rules not being applied

**Solution:**
- Check that mod_rewrite is enabled (Apache): `a2enmod rewrite`
- Verify .htaccess is being read: check Apache configuration for `AllowOverride`
- Add the SetEnvIf rule to your Apache configuration
- Check PHP is receiving the header: `<?php var_dump(getallheaders()); ?>`

### Problem: Empty results but no error

**This is normal if:**
- Your database doesn't contain matching parts
- Search query doesn't match any parts

**To verify:**
- Try searching for a part you know exists in your database
- Test with a very broad search term
- Check the database directly: `SELECT * FROM parts LIMIT 10;`

### Problem: Slow performance with large datasets

**Solutions:**
- Add database indexes for better performance:
  ```sql
  ALTER TABLE parts ADD FULLTEXT INDEX idx_partdescr (partdescr);
  ALTER TABLE parts ADD INDEX idx_partnumber (partnumber);
  ALTER TABLE components ADD INDEX idx_mfgcode (mfgcode);
  ```
- Consider using MySQL full-text search for better performance
- Limit result set size using the `limit` parameter

## Security Best Practices

1. **Always use HTTPS in production** - Never send API tokens over unencrypted HTTP
2. **Store tokens securely** - Use environment variables or secure vaults, never hardcode in source
3. **Set token expiration** - Use time-limited tokens for better security
4. **Rotate tokens regularly** - Revoke and regenerate tokens periodically
5. **Monitor token usage** - Check the "Last Used" column to identify unused tokens
6. **Revoke compromised tokens immediately** - Use the revoke feature, don't just delete
7. **Use appropriate user privileges** - Create dedicated API users with minimal required permissions
8. **Enable HTTPS** - Configure SSL/TLS certificates for your web server
9. **Implement rate limiting** - Consider adding rate limiting at the web server level
10. **Log API access** - Monitor API usage through web server logs

## Performance Optimization

For high-traffic installations, consider:

1. **Enable PHP OpCache** - Improves PHP performance
   ```ini
   ; In php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   ```

2. **Use database query caching** - Configure MySQL query cache
   ```ini
   ; In my.cnf
   query_cache_type = 1
   query_cache_size = 16M
   ```

3. **Add database indexes** - See "Problem: Slow performance" above

4. **Consider a caching layer** - Use Redis or Memcached for frequently accessed data

5. **Enable gzip compression** - Reduce response size
   ```apache
   # In .htaccess
   <IfModule mod_deflate.c>
       AddOutputFilterByType DEFLATE application/json
   </IfModule>
   ```

## Next Steps

- Read the [API Documentation](API_DOCUMENTATION.md) for detailed usage examples
- Check out the [example Python client](example_api_client.py)
- Review the [integration test script](test_api_integration.sh) for testing patterns

## Support

For issues or questions:
- Check the main AXTParts documentation
- Review GitHub issues: https://github.com/BerranRemzi/axtparts/issues
- Consult the demo site: https://axtsystems.com/axtparts/
