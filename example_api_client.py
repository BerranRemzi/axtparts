#!/usr/bin/env python3
"""
Example Python client for AXTParts API

This script demonstrates how to use the AXTParts API to search for parts
in your inventory.

Usage:
    python3 example_api_client.py <api_url> <token> <search_query>

Example:
    python3 example_api_client.py http://localhost/axtparts/api-search.php abc123def456 resistor
"""

import sys
import json
import urllib.request
import urllib.parse
import urllib.error


def search_parts(api_url, token, search_query, limit=10, offset=0, sort=0):
    """
    Search for parts using the AXTParts API
    
    Args:
        api_url (str): The API endpoint URL
        token (str): API authentication token
        search_query (str): Search term
        limit (int): Maximum number of results (default: 10, max: 1000)
        offset (int): Pagination offset (default: 0)
        sort (int): Sort order (0=part number, 1=category, 2=description, 3=footprint, 4=mfg code)
    
    Returns:
        dict: API response
    """
    # Prepare request parameters
    params = {
        'search': search_query,
        'limit': limit,
        'offset': offset,
        'sort': sort
    }
    
    # Build URL with query parameters
    url = f"{api_url}?{urllib.parse.urlencode(params)}"
    
    # Prepare request with authentication header
    headers = {
        'Authorization': f'Bearer {token}',
        'User-Agent': 'AXTParts-Python-Client/1.0'
    }
    
    request = urllib.request.Request(url, headers=headers)
    
    try:
        # Make the request
        with urllib.request.urlopen(request) as response:
            data = json.loads(response.read().decode('utf-8'))
            return data
    except urllib.error.HTTPError as e:
        # Handle HTTP errors
        error_data = json.loads(e.read().decode('utf-8'))
        print(f"HTTP Error {e.code}: {error_data.get('error', 'Unknown error')}")
        return None
    except Exception as e:
        print(f"Error: {str(e)}")
        return None


def print_part_details(part):
    """Print formatted details for a single part"""
    print(f"\nPart Number: {part['partnumber']}")
    print(f"Description: {part['description']}")
    print(f"Category: {part['category'] or 'N/A'}")
    print(f"Footprint: {part['footprint'] or 'N/A'}")
    
    # Stock information
    stock = part.get('stock', {})
    print(f"Stock: {stock.get('total_quantity', 0)} units in {stock.get('location_count', 0)} location(s)")
    
    # Locations
    locations = part.get('locations', [])
    if locations:
        print("Locations:")
        for loc in locations:
            print(f"  - {loc['location']}: {loc['quantity']} units")
            if loc['description']:
                print(f"    {loc['description']}")
            if loc['note']:
                print(f"    Note: {loc['note']}")
    
    # Components
    components = part.get('components', [])
    if components:
        print("Components:")
        for comp in components:
            print(f"  - {comp['manufacturer']}: {comp['manufacturer_code']}")
            if comp['status']:
                print(f"    Status: {comp['status']}")
    
    print("-" * 60)


def main():
    """Main function"""
    if len(sys.argv) < 4:
        print(__doc__)
        sys.exit(1)
    
    api_url = sys.argv[1]
    token = sys.argv[2]
    search_query = sys.argv[3]
    
    print(f"AXTParts API Client")
    print(f"===================")
    print(f"Searching for: {search_query}")
    print()
    
    # Search for parts
    result = search_parts(api_url, token, search_query)
    
    if result and result.get('success'):
        data = result.get('data', {})
        count = data.get('count', 0)
        
        print(f"Found {count} result(s)\n")
        print("=" * 60)
        
        # Print each part
        for part in data.get('results', []):
            print_part_details(part)
        
        # Pagination info
        if count > 0:
            print(f"\nShowing results {data.get('offset', 0) + 1} to {data.get('offset', 0) + count}")
            print(f"Limit: {data.get('limit', 100)}")
    else:
        print("No results or error occurred.")


if __name__ == '__main__':
    main()
