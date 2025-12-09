#!/bin/bash
# Integration test script for AXTParts API
# This script demonstrates how to test the API after installation

echo "AXTParts API Integration Test"
echo "=============================="
echo ""
echo "Prerequisites:"
echo "1. Database must be running with axtparts schema"
echo "2. API tokens migration must be applied"
echo "3. At least one API token must be generated"
echo "4. Web server must be running"
echo ""

# Configuration
API_URL="${1:-http://localhost/axtparts/api-search.php}"
TOKEN="${2:-}"

if [ -z "$TOKEN" ]; then
    echo "Usage: $0 <api_url> <token>"
    echo "Example: $0 http://localhost/axtparts/api-search.php abc123def456..."
    echo ""
    echo "To generate a token:"
    echo "1. Log in to AXTParts as admin"
    echo "2. Go to Admin → API Tokens"
    echo "3. Generate a new token"
    echo "4. Copy the token and use it in this test"
    exit 1
fi

echo "Testing API at: $API_URL"
echo ""

# Test 1: Invalid token
echo "Test 1: Testing with invalid token (should fail with 401)"
echo "-----------------------------------------------------------"
curl -s -w "\nHTTP Status: %{http_code}\n" \
    -H "Authorization: Bearer invalid_token_12345" \
    "$API_URL?search=test" | head -20
echo ""
echo ""

# Test 2: Valid token, no search query
echo "Test 2: Testing with valid token but no search query"
echo "----------------------------------------------------"
curl -s -w "\nHTTP Status: %{http_code}\n" \
    -H "Authorization: Bearer $TOKEN" \
    "$API_URL" | head -20
echo ""
echo ""

# Test 3: Valid token with search query (GET)
echo "Test 3: Testing GET request with search query"
echo "----------------------------------------------"
curl -s -w "\nHTTP Status: %{http_code}\n" \
    -H "Authorization: Bearer $TOKEN" \
    "$API_URL?search=resistor&limit=5" | head -50
echo ""
echo ""

# Test 4: Valid token with search query (POST JSON)
echo "Test 4: Testing POST request with JSON body"
echo "--------------------------------------------"
curl -s -w "\nHTTP Status: %{http_code}\n" \
    -X POST \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"search":"capacitor","limit":3,"sort":2}' \
    "$API_URL" | head -50
echo ""
echo ""

# Test 5: Test pagination
echo "Test 5: Testing pagination with offset"
echo "---------------------------------------"
curl -s -w "\nHTTP Status: %{http_code}\n" \
    -H "Authorization: Bearer $TOKEN" \
    "$API_URL?search=res&limit=2&offset=0" | head -50
echo ""
echo ""

# Test 6: Test sorting
echo "Test 6: Testing different sort orders"
echo "--------------------------------------"
echo "Sort by part number (sort=0):"
curl -s -H "Authorization: Bearer $TOKEN" \
    "$API_URL?search=res&limit=2&sort=0" | grep -A 5 '"results"' | head -20
echo ""
echo "Sort by category (sort=1):"
curl -s -H "Authorization: Bearer $TOKEN" \
    "$API_URL?search=res&limit=2&sort=1" | grep -A 5 '"results"' | head -20
echo ""
echo ""

echo "=============================="
echo "Integration tests completed!"
echo ""
echo "Note: Some tests may return empty results if your database"
echo "doesn't contain matching parts. This is normal."
