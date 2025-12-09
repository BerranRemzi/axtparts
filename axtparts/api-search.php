<?php
// ********************************************
// Copyright 2003-2023 AXT Systems Pty Limited.
// All rights reserved.
// Author: Geoff Swan
// ********************************************
// API endpoint for searching parts inventory
// Supports both GET and POST requests
// Requires Bearer token authentication

header('Content-Type: application/json');
header('Cache-control: no-cache');

// Include configuration
include("config/config-axtparts.php");
require_once("classes/cl-axtparts.php");

// Initialize response
$response = array(
    'success' => false,
    'error' => null,
    'data' => array()
);

// Function to send JSON response
function sendResponse($response, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Function to validate API token
function validateToken($dbh, $token) {
    if (empty($token)) {
        return false;
    }
    
    $q_token = "SELECT t.*, u.uid, u.loginid, u.username, u.status, r.privilege "
            . "\n FROM api_tokens t "
            . "\n INNER JOIN user u ON t.uid = u.uid "
            . "\n LEFT JOIN role r ON u.roleid = r.roleid "
            . "\n WHERE t.token = '" . $dbh->real_escape_string($token) . "' "
            . "\n AND t.is_active = 1 "
            . "\n AND u.status = " . USERSTATUS_ACTIVE;
    
    $s_token = $dbh->query($q_token);
    
    if ($s_token && $s_token->num_rows > 0) {
        $token_data = $s_token->fetch_assoc();
        $s_token->free();
        
        // Check if token has expired
        if ($token_data['expires_at'] !== null) {
            $expires = strtotime($token_data['expires_at']);
            if ($expires < time()) {
                return false;
            }
        }
        
        // Update last_used timestamp
        $q_update = "UPDATE api_tokens "
                . "\n SET last_used = '" . date("Y-m-d H:i:s") . "' "
                . "\n WHERE tokenid = '" . $dbh->real_escape_string($token_data['tokenid']) . "'";
        $dbh->query($q_update);
        
        return $token_data;
    }
    
    return false;
}

// Extract token from Authorization header or query parameter
$token = null;
$headers = getallheaders();

// Check Authorization header (Bearer token)
if (isset($headers['Authorization'])) {
    $matches = array();
    if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
        $token = $matches[1];
    }
} elseif (isset($headers['authorization'])) {
    $matches = array();
    if (preg_match('/Bearer\s+(.+)/', $headers['authorization'], $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

// Fallback to POST parameter
if (empty($token) && isset($_POST['token'])) {
    $token = $_POST['token'];
}

// Validate token
$dbh = new mysqli(PARTSHOST, PARTSUSER, PARTSPASSWD, PARTSDBASE);
if ($dbh->connect_error) {
    $response['error'] = 'Database connection failed';
    sendResponse($response, 500);
}

$token_data = validateToken($dbh, $token);
if (!$token_data) {
    $response['error'] = 'Invalid or expired token';
    sendResponse($response, 401);
}

// Check if user has search privileges
if (!($token_data['privilege'] & TABPRIV_SEARCH)) {
    $response['error'] = 'Insufficient privileges for search';
    sendResponse($response, 403);
}

// Get search parameters
$search_text = '';
$sort_by = 0; // 0=part number, 1=category, 2=description, 3=footprint, 4=mfg code
$limit = 100;
$offset = 0;

// Check for GET parameters
if (isset($_GET['search']) || isset($_GET['q'])) {
    $search_text = isset($_GET['search']) ? trim($_GET['search']) : trim($_GET['q']);
}
if (isset($_GET['sort'])) {
    $sort_by = intval($_GET['sort']);
}
if (isset($_GET['limit'])) {
    $limit = min(intval($_GET['limit']), 1000); // Max 1000 results
}
if (isset($_GET['offset'])) {
    $offset = intval($_GET['offset']);
}

// Check for POST parameters (override GET if present)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support both form data and JSON
    $post_data = $_POST;
    
    if (empty($post_data)) {
        $json_input = file_get_contents('php://input');
        $post_data = json_decode($json_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $post_data = array();
        }
    }
    
    if (isset($post_data['search']) || isset($post_data['q'])) {
        $search_text = isset($post_data['search']) ? trim($post_data['search']) : trim($post_data['q']);
    }
    if (isset($post_data['sort'])) {
        $sort_by = intval($post_data['sort']);
    }
    if (isset($post_data['limit'])) {
        $limit = min(intval($post_data['limit']), 1000);
    }
    if (isset($post_data['offset'])) {
        $offset = intval($post_data['offset']);
    }
}

// Validate limit
if ($limit < 1) {
    $limit = 100;
}

$results = array();

if (!empty($search_text)) {
    // Search in parts by description and part number
    // Note: Using LIKE with leading wildcards (%) can impact performance on large datasets.
    // Consider adding FULLTEXT indexes on partdescr and partnumber columns for better performance:
    // ALTER TABLE parts ADD FULLTEXT INDEX idx_partdescr_fulltext (partdescr);
    // Then use MATCH() AGAINST() syntax instead of LIKE for full-text search
    $q_search = "SELECT p.partid, p.partnumber, p.partdescr, "
            . "\n pg.catdescr, "
            . "\n f.fprintdescr "
            . "\n FROM parts p "
            . "\n LEFT JOIN pgroups pg ON pg.partcatid = p.partcatid "
            . "\n LEFT JOIN footprint f ON f.fprintid = p.footprint "
            . "\n WHERE p.partdescr LIKE '%" . $dbh->real_escape_string($search_text) . "%' "
            . "\n OR p.partnumber LIKE '%" . $dbh->real_escape_string($search_text) . "%' ";
    
    // Add sorting
    switch ($sort_by) {
        case 0:
            $q_search .= "\n ORDER BY p.partnumber ASC ";
            break;
        case 1:
            $q_search .= "\n ORDER BY pg.catdescr ASC ";
            break;
        case 2:
            $q_search .= "\n ORDER BY p.partdescr ASC ";
            break;
        case 3:
            $q_search .= "\n ORDER BY f.fprintdescr ASC ";
            break;
        default:
            $q_search .= "\n ORDER BY p.partdescr ASC ";
            break;
    }
    
    // Add pagination
    $q_search .= "\n LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    
    $s_search = $dbh->query($q_search);
    if ($s_search) {
        while ($r_search = $s_search->fetch_assoc()) {
            $part = array(
                'partid' => intval($r_search['partid']),
                'partnumber' => $r_search['partnumber'],
                'description' => $r_search['partdescr'],
                'category' => $r_search['catdescr'],
                'footprint' => $r_search['fprintdescr']
            );
            
            // Get stock information
            $q_stock = "SELECT SUM(qty) as total_qty, COUNT(*) as location_count "
                    . "\n FROM stock "
                    . "\n WHERE partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
            
            $s_stock = $dbh->query($q_stock);
            if ($s_stock) {
                $r_stock = $s_stock->fetch_assoc();
                $part['stock'] = array(
                    'total_quantity' => $r_stock['total_qty'] ? intval($r_stock['total_qty']) : 0,
                    'location_count' => intval($r_stock['location_count'])
                );
                $s_stock->free();
            } else {
                $part['stock'] = array(
                    'total_quantity' => 0,
                    'location_count' => 0
                );
            }
            
            // Get stock locations
            $q_locations = "SELECT l.locref, l.locdescr, s.qty, s.note "
                    . "\n FROM stock s "
                    . "\n LEFT JOIN locn l ON l.locid = s.locid "
                    . "\n WHERE s.partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
            
            $s_locations = $dbh->query($q_locations);
            $part['locations'] = array();
            if ($s_locations) {
                while ($r_loc = $s_locations->fetch_assoc()) {
                    $part['locations'][] = array(
                        'location' => $r_loc['locref'],
                        'description' => $r_loc['locdescr'],
                        'quantity' => intval($r_loc['qty']),
                        'note' => $r_loc['note']
                    );
                }
                $s_locations->free();
            }
            
            // Get component information
            $q_comp = "SELECT c.mfgname, c.mfgcode, cs.statedescr "
                    . "\n FROM components c "
                    . "\n LEFT JOIN compstates cs ON cs.compstateid = c.compstateid "
                    . "\n WHERE c.partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
            
            $s_comp = $dbh->query($q_comp);
            $part['components'] = array();
            if ($s_comp) {
                while ($r_comp = $s_comp->fetch_assoc()) {
                    $part['components'][] = array(
                        'manufacturer' => $r_comp['mfgname'],
                        'manufacturer_code' => $r_comp['mfgcode'],
                        'status' => $r_comp['statedescr']
                    );
                }
                $s_comp->free();
            }
            
            $results[] = $part;
        }
        $s_search->free();
    }
    
    // Also search in components by manufacturer code
    if (count($results) < $limit) {
        $remaining_limit = $limit - count($results);
        
        // Get part IDs already found
        $found_partids = array();
        foreach ($results as $r) {
            $found_partids[] = $r['partid'];
        }
        
        // Search in components by manufacturer code and name
        // Note: Using LIKE with leading wildcards (%) can impact performance on large datasets.
        // Consider adding indexes: ALTER TABLE components ADD INDEX idx_mfgcode (mfgcode);
        $q_comp_search = "SELECT DISTINCT p.partid, p.partnumber, p.partdescr, "
                . "\n pg.catdescr, "
                . "\n f.fprintdescr "
                . "\n FROM components c "
                . "\n INNER JOIN parts p ON p.partid = c.partid "
                . "\n LEFT JOIN pgroups pg ON pg.partcatid = p.partcatid "
                . "\n LEFT JOIN footprint f ON f.fprintid = p.footprint "
                . "\n WHERE (c.mfgcode LIKE '%" . $dbh->real_escape_string($search_text) . "%' "
                . "\n OR c.mfgname LIKE '%" . $dbh->real_escape_string($search_text) . "%') ";
        
        // Exclude already found parts
        if (count($found_partids) > 0) {
            $q_comp_search .= "\n AND p.partid NOT IN (" . implode(',', array_map('intval', $found_partids)) . ") ";
        }
        
        // Add sorting
        switch ($sort_by) {
            case 0:
                $q_comp_search .= "\n ORDER BY p.partnumber ASC ";
                break;
            case 1:
                $q_comp_search .= "\n ORDER BY pg.catdescr ASC ";
                break;
            case 2:
                $q_comp_search .= "\n ORDER BY p.partdescr ASC ";
                break;
            case 3:
                $q_comp_search .= "\n ORDER BY f.fprintdescr ASC ";
                break;
            case 4:
                $q_comp_search .= "\n ORDER BY c.mfgcode ASC ";
                break;
            default:
                $q_comp_search .= "\n ORDER BY c.mfgcode ASC ";
                break;
        }
        
        $q_comp_search .= "\n LIMIT " . intval($remaining_limit);
        
        $s_comp_search = $dbh->query($q_comp_search);
        if ($s_comp_search) {
            while ($r_search = $s_comp_search->fetch_assoc()) {
                $part = array(
                    'partid' => intval($r_search['partid']),
                    'partnumber' => $r_search['partnumber'],
                    'description' => $r_search['partdescr'],
                    'category' => $r_search['catdescr'],
                    'footprint' => $r_search['fprintdescr']
                );
                
                // Get stock information
                $q_stock = "SELECT SUM(qty) as total_qty, COUNT(*) as location_count "
                        . "\n FROM stock "
                        . "\n WHERE partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
                
                $s_stock = $dbh->query($q_stock);
                if ($s_stock) {
                    $r_stock = $s_stock->fetch_assoc();
                    $part['stock'] = array(
                        'total_quantity' => $r_stock['total_qty'] ? intval($r_stock['total_qty']) : 0,
                        'location_count' => intval($r_stock['location_count'])
                    );
                    $s_stock->free();
                } else {
                    $part['stock'] = array(
                        'total_quantity' => 0,
                        'location_count' => 0
                    );
                }
                
                // Get stock locations
                $q_locations = "SELECT l.locref, l.locdescr, s.qty, s.note "
                        . "\n FROM stock s "
                        . "\n LEFT JOIN locn l ON l.locid = s.locid "
                        . "\n WHERE s.partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
                
                $s_locations = $dbh->query($q_locations);
                $part['locations'] = array();
                if ($s_locations) {
                    while ($r_loc = $s_locations->fetch_assoc()) {
                        $part['locations'][] = array(
                            'location' => $r_loc['locref'],
                            'description' => $r_loc['locdescr'],
                            'quantity' => intval($r_loc['qty']),
                            'note' => $r_loc['note']
                        );
                    }
                    $s_locations->free();
                }
                
                // Get component information
                $q_comp = "SELECT c.mfgname, c.mfgcode, cs.statedescr "
                        . "\n FROM components c "
                        . "\n LEFT JOIN compstates cs ON cs.compstateid = c.compstateid "
                        . "\n WHERE c.partid = '" . $dbh->real_escape_string($r_search['partid']) . "'";
                
                $s_comp = $dbh->query($q_comp);
                $part['components'] = array();
                if ($s_comp) {
                    while ($r_comp = $s_comp->fetch_assoc()) {
                        $part['components'][] = array(
                            'manufacturer' => $r_comp['mfgname'],
                            'manufacturer_code' => $r_comp['mfgcode'],
                            'status' => $r_comp['statedescr']
                        );
                    }
                    $s_comp->free();
                }
                
                $results[] = $part;
            }
            $s_comp_search->free();
        }
    }
}

$dbh->close();

// Prepare successful response
$response['success'] = true;
$response['data'] = array(
    'query' => $search_text,
    'count' => count($results),
    'limit' => $limit,
    'offset' => $offset,
    'results' => $results
);

sendResponse($response, 200);
?>
