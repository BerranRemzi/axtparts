<?php
// ********************************************
// Copyright 2003-2023 AXT Systems Pty Limited.
// All rights reserved.
// Author: Geoff Swan
// ********************************************
// API Token Management

session_start();
header("Cache-control: private");
header('Content-Type: text/html; charset=UTF-8');

include("config/config-axtparts.php");
require_once("classes/cl-axtparts.php");
$formfile = "frm-api-tokens.php";
$formname = "apitokens";
$formtitle = "API Token Management";
$ontab = "Admin";

$myparts = new axtparts();

if ($myparts->SessionCheck() === false)
{
	$myparts->AlertMeTo("Session Expired.");
	$myparts->VectorMeTo(PAGE_LOGOUT);
	die();
}

$username = $myparts->SessionMeName();
$uid = $myparts->SessionMeUID();

if ($myparts->SessionMePrivilegeBit(UPRIV_USERADMIN) !== true)
{
	$myparts->AlertMeTo("Insufficient privileges.");
	die();
}

$dbh = new mysqli(PARTSHOST, PARTSUSER, PARTSPASSWD, PARTSDBASE);
if ($dbh->connect_error)
{
	$myparts->AlertMeTo("Could not connect to database");
	$myparts->VectorMeTo(PAGE_LOGOUT);
	die();
}

$message = "";
$new_token_display = "";

// Handle form submissions
if (isset($_POST["btn_generate"]))
{
	$token_name = isset($_POST["token_name"]) ? trim($_POST["token_name"]) : "";
	$token_uid = isset($_POST["token_uid"]) ? intval($_POST["token_uid"]) : $uid;
	$expires_days = isset($_POST["expires_days"]) ? intval($_POST["expires_days"]) : 0;
	
	if (empty($token_name)) {
		$message = "<div class='alert alert-danger'>Token name is required.</div>";
	} else {
		$expires_at = null;
		if ($expires_days > 0) {
			$expires_at = date("Y-m-d H:i:s", strtotime("+{$expires_days} days"));
		}
		
		$result = $myparts->TokenGenerate($dbh, $token_uid, $token_name, $expires_at);
		if ($result["status"]) {
			$new_token_display = "<div class='alert alert-success'>"
				. "<strong>Token generated successfully!</strong><br>"
				. "Token: <code style='background: #f5f5f5; padding: 5px; display: block; margin-top: 10px; word-break: break-all;'>"
				. htmlentities($result["token"])
				. "</code><br>"
				. "<small class='text-danger'>Save this token now - you won't be able to see it again!</small>"
				. "</div>";
		} else {
			$message = "<div class='alert alert-danger'>Error generating token: " . htmlentities($result["error"]) . "</div>";
		}
	}
}

if (isset($_POST["btn_revoke"]))
{
	$tokenid = isset($_POST["tokenid"]) ? intval($_POST["tokenid"]) : 0;
	if ($tokenid > 0) {
		$result = $myparts->TokenRevoke($dbh, $tokenid);
		if ($result["status"]) {
			$message = "<div class='alert alert-success'>Token revoked successfully.</div>";
		} else {
			$message = "<div class='alert alert-danger'>Error revoking token: " . htmlentities($result["error"]) . "</div>";
		}
	}
}

if (isset($_POST["btn_delete"]))
{
	$tokenid = isset($_POST["tokenid"]) ? intval($_POST["tokenid"]) : 0;
	if ($tokenid > 0) {
		$result = $myparts->TokenDelete($dbh, $tokenid);
		if ($result["status"]) {
			$message = "<div class='alert alert-success'>Token deleted successfully.</div>";
		} else {
			$message = "<div class='alert alert-danger'>Error deleting token: " . htmlentities($result["error"]) . "</div>";
		}
	}
}

// Get all tokens
$tokens = $myparts->TokenRead($dbh, false);

// Get all users for dropdown
$q_users = "SELECT uid, loginid, username FROM user WHERE status = " . USERSTATUS_ACTIVE . " ORDER BY username";
$s_users = $dbh->query($q_users);
$users = array();
if ($s_users) {
	while ($r = $s_users->fetch_assoc()) {
		$users[] = $r;
	}
	$s_users->free();
}

?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php print $formtitle; ?></title>
	<link rel="stylesheet" type="text/css" href="css/axtparts1.css">
	<style>
		.token-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
		.token-table th, .token-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
		.token-table th { background-color: #f0f0f0; }
		.token-status { padding: 3px 8px; border-radius: 3px; font-size: 0.9em; }
		.token-active { background-color: #d4edda; color: #155724; }
		.token-inactive { background-color: #f8d7da; color: #721c24; }
		.token-expired { background-color: #fff3cd; color: #856404; }
		.form-group { margin-bottom: 15px; }
		.form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
		.form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
		.btn { padding: 8px 16px; margin: 5px; cursor: pointer; border: none; border-radius: 4px; }
		.btn-primary { background-color: #007bff; color: white; }
		.btn-danger { background-color: #dc3545; color: white; }
		.btn-warning { background-color: #ffc107; color: black; }
		.alert { padding: 12px; margin: 10px 0; border-radius: 4px; }
		.alert-success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
		.alert-danger { background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
		.text-danger { color: #dc3545; }
	</style>
</head>
<body>
<div class="body-container">
	<?php $myparts->FormRender_Tabs(array("ontab" => $ontab)); ?>
	
	<div class="form-container">
		<h1><?php print htmlentities($formtitle); ?></h1>
		
		<?php print $message; ?>
		<?php print $new_token_display; ?>
		
		<div style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
			<h2>Generate New Token</h2>
			<form method="post" action="<?php print $formfile; ?>">
				<div class="form-group">
					<label for="token_name">Token Name/Description:</label>
					<input type="text" id="token_name" name="token_name" required placeholder="e.g., Mobile App Token, Integration Key">
				</div>
				
				<div class="form-group">
					<label for="token_uid">User:</label>
					<select id="token_uid" name="token_uid">
						<?php foreach ($users as $user): ?>
							<option value="<?php print $user['uid']; ?>" <?php if ($user['uid'] == $uid) print 'selected'; ?>>
								<?php print htmlentities($user['username']) . ' (' . htmlentities($user['loginid']) . ')'; ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				
				<div class="form-group">
					<label for="expires_days">Expires In (days, 0 = never):</label>
					<input type="number" id="expires_days" name="expires_days" min="0" value="0">
				</div>
				
				<button type="submit" name="btn_generate" class="btn btn-primary">Generate Token</button>
			</form>
		</div>
		
		<h2>Existing Tokens</h2>
		
		<?php if ($tokens["status"] && count($tokens["tokens"]) > 0): ?>
			<table class="token-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>User</th>
						<th>Created</th>
						<th>Last Used</th>
						<th>Expires</th>
						<th>Status</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tokens["tokens"] as $token): 
						$is_expired = false;
						if ($token['expires_at'] !== null) {
							$is_expired = strtotime($token['expires_at']) < time();
						}
						$status_class = $token['is_active'] == 1 ? ($is_expired ? 'token-expired' : 'token-active') : 'token-inactive';
						$status_text = $token['is_active'] == 1 ? ($is_expired ? 'Expired' : 'Active') : 'Revoked';
					?>
					<tr>
						<td><?php print $token['tokenid']; ?></td>
						<td><?php print htmlentities($token['token_name']); ?></td>
						<td><?php print htmlentities($token['username']) . ' (' . htmlentities($token['loginid']) . ')'; ?></td>
						<td><?php print $token['created_at']; ?></td>
						<td><?php print $token['last_used'] ? $token['last_used'] : 'Never'; ?></td>
						<td><?php print $token['expires_at'] ? $token['expires_at'] : 'Never'; ?></td>
						<td><span class="token-status <?php print $status_class; ?>"><?php print $status_text; ?></span></td>
						<td>
							<?php if ($token['is_active'] == 1): ?>
								<form method="post" style="display: inline;">
									<input type="hidden" name="tokenid" value="<?php print $token['tokenid']; ?>">
									<button type="submit" name="btn_revoke" class="btn btn-warning" 
										onclick="return confirm('Are you sure you want to revoke this token?');">Revoke</button>
								</form>
							<?php endif; ?>
							<form method="post" style="display: inline;">
								<input type="hidden" name="tokenid" value="<?php print $token['tokenid']; ?>">
								<button type="submit" name="btn_delete" class="btn btn-danger" 
									onclick="return confirm('Are you sure you want to permanently delete this token?');">Delete</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else: ?>
			<p>No tokens found.</p>
		<?php endif; ?>
		
		<div style="margin-top: 30px; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196F3;">
			<h3>API Usage Instructions</h3>
			<p><strong>Endpoint:</strong> <code><?php print $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api-search.php'; ?></code></p>
			
			<p><strong>Authentication:</strong> Include the token in the Authorization header:</p>
			<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">Authorization: Bearer YOUR_TOKEN_HERE</pre>
			
			<p><strong>GET Request Example:</strong></p>
			<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">GET /axtparts/api-search.php?search=resistor&limit=10&sort=0
Authorization: Bearer YOUR_TOKEN_HERE</pre>
			
			<p><strong>POST Request Example (JSON):</strong></p>
			<pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">POST /axtparts/api-search.php
Authorization: Bearer YOUR_TOKEN_HERE
Content-Type: application/json

{
  "search": "resistor",
  "limit": 10,
  "sort": 0
}</pre>
			
			<p><strong>Parameters:</strong></p>
			<ul>
				<li><code>search</code> or <code>q</code>: Search query (part description, number, or manufacturer code)</li>
				<li><code>sort</code>: Sort order (0=part number, 1=category, 2=description, 3=footprint, 4=mfg code)</li>
				<li><code>limit</code>: Maximum results to return (default: 100, max: 1000)</li>
				<li><code>offset</code>: Pagination offset (default: 0)</li>
			</ul>
		</div>
	</div>
	
	<div class="footer-container">
		<?php print SYSTEMHEADING; ?> v<?php print ENGPARTSVERSION; ?> - <?php print SYSTEMBRANDING; ?>
	</div>
</div>
</body>
</html>
<?php
$dbh->close();
?>
