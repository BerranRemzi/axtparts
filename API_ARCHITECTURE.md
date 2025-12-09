# AXTParts API Architecture

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Client Applications                       │
│  (Mobile Apps, Web Apps, Scripts, Integration Services)          │
└────────────┬────────────────────────────────────┬────────────────┘
             │                                    │
             │ HTTP/HTTPS Request                 │ HTTP/HTTPS Request
             │ (GET or POST)                      │ (JSON Body)
             │                                    │
             ▼                                    ▼
┌────────────────────────────────────────────────────────────────┐
│                       API Endpoint                              │
│                  axtparts/api-search.php                        │
│                                                                 │
│  1. Extract Token from Authorization Header or Parameters      │
│  2. Validate Token & Check User Privileges                     │
│  3. Parse Search Parameters                                    │
│  4. Execute Database Queries                                   │
│  5. Format Results as JSON                                     │
│  6. Return Response                                            │
└────────────┬───────────────────────────────────────────────────┘
             │
             │ Database Queries
             │
             ▼
┌────────────────────────────────────────────────────────────────┐
│                      MySQL Database                             │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │  api_tokens  │  │    parts     │  │  components  │         │
│  │              │  │              │  │              │         │
│  │ - tokenid    │  │ - partid     │  │ - compid     │         │
│  │ - token      │  │ - partnumber │  │ - mfgcode    │         │
│  │ - uid        │  │ - partdescr  │  │ - mfgname    │         │
│  │ - created_at │  │ - footprint  │  └──────────────┘         │
│  │ - expires_at │  │ - partcatid  │                           │
│  │ - is_active  │  └──────────────┘                           │
│  └──────────────┘                                              │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │    stock     │  │     locn     │  │     user     │         │
│  │              │  │              │  │              │         │
│  │ - stockid    │  │ - locid      │  │ - uid        │         │
│  │ - qty        │  │ - locref     │  │ - loginid    │         │
│  │ - partid     │  │ - locdescr   │  │ - privilege  │         │
│  │ - locid      │  └──────────────┘  └──────────────┘         │
│  └──────────────┘                                              │
└────────────────────────────────────────────────────────────────┘
```

## Authentication Flow

```
┌──────────┐
│  Client  │
└────┬─────┘
     │
     │ 1. Request with Token
     │    GET /api-search.php?search=resistor
     │    Authorization: Bearer abc123...
     │
     ▼
┌─────────────────────────────────────────────────┐
│           API Endpoint (api-search.php)          │
│                                                  │
│  2. Extract Token                                │
│     - Check Authorization header                 │
│     - Fallback to query/POST parameter          │
│                                                  │
│  3. Validate Token                               │
│     Query: SELECT * FROM api_tokens              │
│            WHERE token = ? AND is_active = 1     │
│                                                  │
│  4. Check Token Expiration                       │
│     IF expires_at < NOW() THEN reject           │
│                                                  │
│  5. Verify User Status & Privileges              │
│     JOIN with user table                         │
│     Check: status = ACTIVE                       │
│     Check: privilege & TABPRIV_SEARCH            │
│                                                  │
│  6. Update Last Used Timestamp                   │
│     UPDATE api_tokens SET last_used = NOW()      │
│                                                  │
└────┬─────────────────────────────────────────────┘
     │
     │ ✓ Token Valid
     │
     ▼
┌─────────────────────────────────────────────────┐
│          Execute Search Query                    │
│                                                  │
│  7. Search in parts table                        │
│     WHERE partdescr LIKE '%search%'              │
│        OR partnumber LIKE '%search%'             │
│                                                  │
│  8. Search in components table                   │
│     WHERE mfgcode LIKE '%search%'                │
│        OR mfgname LIKE '%search%'                │
│                                                  │
│  9. For each result, gather:                     │
│     - Stock information                          │
│     - Location details                           │
│     - Component details                          │
│                                                  │
│  10. Format as JSON                              │
│                                                  │
└────┬─────────────────────────────────────────────┘
     │
     │ JSON Response
     │
     ▼
┌──────────┐
│  Client  │
└──────────┘
```

## Token Management Flow

```
┌──────────────┐
│  Admin User  │
└──────┬───────┘
       │
       │ 1. Login to AXTParts
       │
       ▼
┌─────────────────────────────────────────────┐
│    Web Interface (frm-api-tokens.php)        │
│                                              │
│  2. Navigate to Admin → API Tokens          │
│                                              │
│  3. Fill Token Generation Form:             │
│     - Token Name: "Mobile App"              │
│     - User: Select user                     │
│     - Expires In: 365 days                  │
│                                              │
│  4. Click "Generate Token"                  │
│                                              │
└──────┬───────────────────────────────────────┘
       │
       │ Generate Token Request
       │
       ▼
┌─────────────────────────────────────────────┐
│   axtparts Class (cl-axtparts.php)           │
│                                              │
│  5. TokenGenerate() method:                 │
│     - Generate random 256-bit token         │
│       $token = bin2hex(random_bytes(32))    │
│     - Calculate expiration date             │
│     - Insert into api_tokens table          │
│                                              │
└──────┬───────────────────────────────────────┘
       │
       │ Store in Database
       │
       ▼
┌─────────────────────────────────────────────┐
│           MySQL Database                     │
│                                              │
│  INSERT INTO api_tokens                      │
│  SET token = 'abc123...',                    │
│      token_name = 'Mobile App',              │
│      uid = 5,                                │
│      created_at = NOW(),                     │
│      expires_at = DATE_ADD(NOW(), 365 DAY),  │
│      is_active = 1                           │
│                                              │
└──────┬───────────────────────────────────────┘
       │
       │ Return Token
       │
       ▼
┌─────────────────────────────────────────────┐
│    Display Token (ONE TIME ONLY)             │
│                                              │
│  ┌─────────────────────────────────────┐    │
│  │ Token generated successfully!       │    │
│  │                                     │    │
│  │ Token: abc123def456...              │    │
│  │                                     │    │
│  │ ⚠️  Save this now - you won't see   │    │
│  │    it again!                        │    │
│  └─────────────────────────────────────┘    │
│                                              │
└──────────────────────────────────────────────┘
```

## Request/Response Flow Examples

### Example 1: Successful Search (GET)

```
Request:
  GET /axtparts/api-search.php?search=resistor&limit=5&sort=0
  Authorization: Bearer abc123def456...

Response (200 OK):
  {
    "success": true,
    "error": null,
    "data": {
      "query": "resistor",
      "count": 5,
      "limit": 5,
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
          "locations": [...],
          "components": [...]
        }
      ]
    }
  }
```

### Example 2: Invalid Token

```
Request:
  GET /axtparts/api-search.php?search=resistor
  Authorization: Bearer invalid_token

Response (401 Unauthorized):
  {
    "success": false,
    "error": "Invalid or expired token",
    "data": []
  }
```

### Example 3: Insufficient Privileges

```
Request:
  GET /axtparts/api-search.php?search=resistor
  Authorization: Bearer valid_token_but_no_search_privilege

Response (403 Forbidden):
  {
    "success": false,
    "error": "Insufficient privileges for search",
    "data": []
  }
```

## Security Layers

```
┌─────────────────────────────────────────────────────┐
│ Layer 1: Transport Security (HTTPS)                 │
│ - Encrypts all traffic                              │
│ - Prevents token interception                       │
└─────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────┐
│ Layer 2: Token Authentication                       │
│ - 256-bit random token                              │
│ - Unique constraint in database                     │
│ - Bearer token format                               │
└─────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────┐
│ Layer 3: Token Validation                           │
│ - Check token exists in database                    │
│ - Check is_active = 1                               │
│ - Check not expired                                 │
└─────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────┐
│ Layer 4: User Authorization                         │
│ - Check user status = ACTIVE                        │
│ - Check user has TABPRIV_SEARCH privilege           │
│ - Inherit user's permission levels                  │
└─────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────┐
│ Layer 5: Input Validation                           │
│ - Sanitize search parameters                        │
│ - Use prepared statements / real_escape_string      │
│ - Validate numeric inputs                           │
└─────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────┐
│ Layer 6: Rate Limiting (Recommended)                │
│ - Implement at web server level                     │
│ - Limit requests per token/IP                       │
│ - Prevent abuse                                     │
└─────────────────────────────────────────────────────┘
```

## Data Flow

```
Search Query: "resistor"
     │
     ├─→ Parts Table Search
     │   └─→ Match in: partdescr, partnumber
     │       └─→ Result: AX000123A (Resistor 10K 0805)
     │           │
     │           ├─→ Stock Table Query
     │           │   └─→ SUM(qty), COUNT(locations)
     │           │       └─→ Result: 500 units in 2 locations
     │           │
     │           ├─→ Location Details Query
     │           │   └─→ JOIN stock + locn tables
     │           │       └─→ Result: [A1-B2: 300 units, A1-B3: 200 units]
     │           │
     │           └─→ Component Details Query
     │               └─→ JOIN components table
     │                   └─→ Result: [Yageo RC0805FR-0710KL]
     │
     └─→ Components Table Search
         └─→ Match in: mfgcode, mfgname
             └─→ Result: Additional parts with matching mfg codes
                 └─→ (Same enrichment as above)
```

## Performance Optimization

```
Without Indexes:
  Query Time: 500ms-2000ms (large datasets)
  Method: Full table scan with LIKE '%search%'
  
With Recommended Indexes:
  ALTER TABLE parts ADD FULLTEXT INDEX idx_partdescr_fulltext (partdescr);
  ALTER TABLE parts ADD INDEX idx_partnumber (partnumber);
  ALTER TABLE components ADD INDEX idx_mfgcode (mfgcode);
  
  Query Time: 50ms-200ms
  Method: Index scan + MATCH() AGAINST() for full-text
```

## Component Interaction Matrix

```
┌─────────────────┬──────────┬──────────┬──────────┬──────────┐
│                 │   API    │  Token   │ axtparts │ Database │
│                 │ Endpoint │   Mgmt   │  Class   │          │
├─────────────────┼──────────┼──────────┼──────────┼──────────┤
│ Search Parts    │    ●     │          │          │    ●     │
│ Validate Token  │    ●     │          │          │    ●     │
│ Generate Token  │          │    ●     │    ●     │    ●     │
│ Revoke Token    │          │    ●     │    ●     │    ●     │
│ Delete Token    │          │    ●     │    ●     │    ●     │
│ List Tokens     │          │    ●     │    ●     │    ●     │
│ Check Privilege │    ●     │    ●     │    ●     │    ●     │
└─────────────────┴──────────┴──────────┴──────────┴──────────┘

Legend: ● = Component involved in operation
```

## Deployment Checklist

- [ ] Apply database migration (api_tokens table)
- [ ] Configure web server to pass Authorization headers
- [ ] Enable HTTPS/SSL certificates
- [ ] Generate first admin API token
- [ ] Test API with curl/Postman
- [ ] Run integration test script
- [ ] Configure rate limiting (optional)
- [ ] Set up monitoring/logging
- [ ] Document internal API usage policies
- [ ] Train users on token management

## Files and Their Roles

| File | Role | Lines | Purpose |
|------|------|-------|---------|
| api-search.php | API Endpoint | 431 | Main API logic, handles requests |
| frm-api-tokens.php | Admin UI | 281 | Token management interface |
| cl-axtparts.php | Backend | +143 | Token CRUD operations |
| axtparts-api-tokens.sql | Database | 19 | Token table schema |
| API_DOCUMENTATION.md | Docs | 275 | Usage guide and examples |
| INSTALLATION_API.md | Docs | 259 | Setup and troubleshooting |
| test_api_integration.sh | Testing | 99 | Integration tests |
| example_api_client.py | Example | 143 | Reference implementation |

**Total: 1,909 lines added**
