<?php
// ********************************************
// Copyright 2003-2023 AXT Systems Pty Limited.
// All rights reserved.
// Author: Geoff Swan
// ********************************************
// Stateless JSON API endpoint for axtparts.
//
// Authentication is per-user: each client sends their login ID and
// their password hash (the SSHA1 base-64 string from user.passwd)
// via the headers X-API-User and X-API-Token respectively.
//
// Usage:
//   GET  api.php?action=ping
//   GET  api.php?action=list_parts&search=STLink
//   GET  api.php?action=get_part&partid=123
//   POST api.php?action=create_part   (JSON body)
//   POST api.php?action=add_stock     (JSON body)
//
// All responses are JSON:
//   { "status": true|false, "data": ..., "error": "..." }

include("config/config-axtparts.php");
require_once("classes/cl-axtparts.php");
require_once("classes/cl-api.php");

// Always respond as JSON.
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

// --- Collect the request parameters --------------------------------

$action = false;
if (isset($_GET["action"]))
    $action = trim($_GET["action"]);

// Authentication credentials come from headers (preferred) or query
// parameters as a fallback.
$api_user  = false;
$api_token = false;

if (isset($_SERVER["HTTP_X_API_USER"]))
    $api_user = trim($_SERVER["HTTP_X_API_USER"]);
elseif (isset($_GET["user"]))
    $api_user = trim($_GET["user"]);

if (isset($_SERVER["HTTP_X_API_TOKEN"]))
    $api_token = trim($_SERVER["HTTP_X_API_TOKEN"]);
elseif (isset($_GET["token"]))
    $api_token = trim($_GET["token"]);

// --- Connect to the database ---------------------------------------

$dbh = new mysqli(PARTSHOST, PARTSUSER, PARTSPASSWD, PARTSDBASE);
if ($dbh->connect_error)
{
    echo json_encode(array("status" => false, "data" => null, "error" => "Database connection failed."));
    die();
}

$api = new axtparts_api($dbh);

// --- Authenticate ---------------------------------------------------

if ($api_user === false || $api_token === false)
{
    echo json_encode(array("status" => false, "data" => null, "error" => "Authentication required: provide X-API-User and X-API-Token headers."));
    $dbh->close();
    die();
}

if ($api->Authenticate($api_user, $api_token) === false)
{
    echo json_encode(array("status" => false, "data" => null, "error" => "Authentication failed."));
    $dbh->close();
    die();
}

// --- Read JSON body for POST actions --------------------------------

$body = null;
if ($_SERVER["REQUEST_METHOD"] === "POST")
{
    $raw = file_get_contents("php://input");
    if ($raw !== false && $raw !== "")
    {
        $body = json_decode($raw, true);
        if (!is_array($body))
            $body = array();
    }
    else
    {
        $body = array();
    }
}

// --- Dispatch -------------------------------------------------------

$rv = null;

switch ($action)
{
    case "ping":
        $rv = $api->Ping();
        break;

    case "list_parts":
        $filters = array();
        if (isset($_GET["partcatid"])) $filters["partcatid"] = $_GET["partcatid"];
        if (isset($_GET["search"]))    $filters["search"]    = $_GET["search"];
        if (isset($_GET["limit"]))     $filters["limit"]     = $_GET["limit"];
        if (isset($_GET["offset"]))    $filters["offset"]    = $_GET["offset"];
        $rv = $api->ListParts($filters);
        break;

    case "get_part":
        $partid = isset($_GET["partid"]) ? $_GET["partid"] : (isset($body["partid"]) ? $body["partid"] : false);
        $rv = $api->GetPart($partid);
        break;

    case "create_part":
        if ($body === null)
        {
            $rv = $api->Fail("POST body (JSON) is required for this action.");
            break;
        }
        $partdescr = isset($body["partdescr"]) ? $body["partdescr"] : "";
        $partcatid = isset($body["partcatid"]) ? $body["partcatid"] : 0;
        $footprint = isset($body["footprint"]) ? $body["footprint"] : 0;
        $rv = $api->CreatePart($partdescr, $partcatid, $footprint);
        break;

    case "add_stock":
        if ($body === null)
        {
            $rv = $api->Fail("POST body (JSON) is required for this action.");
            break;
        }
        $partid = isset($body["partid"]) ? $body["partid"] : false;
        $locid  = isset($body["locid"])  ? $body["locid"]  : false;
        $qty    = isset($body["qty"])    ? $body["qty"]    : 1;
        $note   = isset($body["note"])   ? $body["note"]   : "";
        $rv = $api->AddStock($partid, $locid, $qty, $note);
        break;

    default:
        $rv = $api->Fail("Unknown or missing action.");
        break;
}

$dbh->close();

$api->SendJSON($rv);