# AXTParts API Documentation

## Overview

The AXTParts API provides token-secured access to search parts in your inventory. The API supports both GET and POST requests and returns results in JSON format.

## Authentication

All API requests require a valid API token. Tokens are managed through the admin interface at `/axtparts/frm-api-tokens.php` (requires admin privileges).

### Token Authentication Methods

You can authenticate using any of these methods:

1. **Authorization Header (Recommended)**
```
Authorization: Bearer YOUR_TOKEN_HERE
```

2. **Query Parameter**
```
?token=YOUR_TOKEN_HERE
```

3. **POST Parameter**
```
token=YOUR_TOKEN_HERE
```

## Endpoints

### Search Parts

Search for parts in the inventory by description, part number, or manufacturer code.

**Endpoint:** `/axtparts/api-search.php`

**Methods:** GET, POST

**Authentication:** Required

#### Parameters

| Parameter | Type | Description | Default |
|-----------|------|-------------|---------|
| `search` or `q` | string | Search query (searches in part description, part number, and manufacturer code) | - |
| `sort` | integer | Sort order: 0=part number, 1=category, 2=description, 3=footprint, 4=manufacturer code | 0 |
| `limit` | integer | Maximum number of results to return (max: 1000) | 100 |
| `offset` | integer | Pagination offset | 0 |

#### Response Format

```json
{
  "success": true,
  "error": null,
  "data": {
    "query": "resistor",
    "count": 2,
    "limit": 100,
    "offset": 0,
    "results": [
      {
        "partid": 123,
        "partnumber": "AX000123A",
        "description": "Resistor 10K 0805",
        "category": "Resistors",
        "footprint": "0805",
        "stock": {
          "total_quantity": 500,
          "location_count": 2
        },
        "locations": [
          {
            "location": "A1-B2",
            "description": "SMD Storage - Shelf A1",
            "quantity": 300,
            "note": "Reel 1"
          },
          {
            "location": "A1-B3",
            "description": "SMD Storage - Shelf A1",
            "quantity": 200,
            "note": "Reel 2"
          }
        ],
        "components": [
          {
            "manufacturer": "Yageo",
            "manufacturer_code": "RC0805FR-0710KL",
            "status": "Active"
          }
        ]
      }
    ]
  }
}
```

#### Error Response Format

```json
{
  "success": false,
  "error": "Invalid or expired token",
  "data": []
}
```

## Example Requests

### cURL (GET Request)

```bash
curl -X GET "https://yourdomain.com/axtparts/api-search.php?search=resistor&limit=10" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### cURL (POST Request with JSON)

```bash
curl -X POST "https://yourdomain.com/axtparts/api-search.php" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "search": "resistor",
    "limit": 10,
    "sort": 2
  }'
```

### Python

```python
import requests

url = "https://yourdomain.com/axtparts/api-search.php"
headers = {
    "Authorization": "Bearer YOUR_TOKEN_HERE"
}
params = {
    "search": "resistor",
    "limit": 10
}

response = requests.get(url, headers=headers, params=params)
data = response.json()

if data['success']:
    for part in data['data']['results']:
        print(f"{part['partnumber']}: {part['description']}")
```

### JavaScript (Fetch API)

```javascript
const url = 'https://yourdomain.com/axtparts/api-search.php';
const token = 'YOUR_TOKEN_HERE';

fetch(url + '?search=resistor&limit=10', {
  headers: {
    'Authorization': `Bearer ${token}`
  }
})
.then(response => response.json())
.then(data => {
  if (data.success) {
    data.data.results.forEach(part => {
      console.log(`${part.partnumber}: ${part.description}`);
    });
  }
})
.catch(error => console.error('Error:', error));
```

### PHP

```php
<?php
$url = 'https://yourdomain.com/axtparts/api-search.php';
$token = 'YOUR_TOKEN_HERE';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url . '?search=resistor&limit=10');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $token
));

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

if ($data['success']) {
    foreach ($data['data']['results'] as $part) {
        echo $part['partnumber'] . ': ' . $part['description'] . "\n";
    }
}
?>
```

## HTTP Status Codes

| Status Code | Description |
|-------------|-------------|
| 200 | Success |
| 401 | Unauthorized (invalid or expired token) |
| 403 | Forbidden (insufficient privileges) |
| 500 | Internal Server Error |

## Token Management

### Generating Tokens

1. Log in to AXTParts with admin privileges
2. Navigate to Admin → API Tokens (`frm-api-tokens.php`)
3. Fill in the token details:
   - **Token Name**: A descriptive name for the token
   - **User**: The user account associated with the token
   - **Expires In**: Number of days until expiration (0 = never expires)
4. Click "Generate Token"
5. **Important:** Copy the generated token immediately - it will only be shown once!

### Token Security

- Store tokens securely (environment variables, secure vaults)
- Never commit tokens to source control
- Use HTTPS for all API requests
- Revoke unused or compromised tokens immediately
- Set appropriate expiration dates for tokens
- Each token is associated with a user and inherits that user's privileges

### Revoking Tokens

To revoke a token:
1. Navigate to Admin → API Tokens
2. Find the token in the list
3. Click "Revoke" to deactivate it (can be restored by admin if needed)
4. Click "Delete" to permanently remove it

## Installation

### Database Setup

Run the following SQL migration to add API token support to your existing AXTParts installation:

```bash
mysql -u root -p axtparts < sql/axtparts-api-tokens.sql
```

Or manually execute the SQL in the file `sql/axtparts-api-tokens.sql`.

### Web Server Configuration

Ensure your web server is configured to:
1. Allow access to `api-search.php`
2. Pass Authorization headers to PHP (may require configuration in some environments)

For Apache, add to `.htaccess` if not already present:
```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## Limitations

- Maximum 1000 results per request
- API inherits user privileges (token must belong to a user with search privileges)
- Rate limiting is not implemented by default (consider adding at web server level)
- Search is case-insensitive substring match

## Support

For issues or questions, please refer to the main AXTParts repository:
https://github.com/BerranRemzi/axtparts
