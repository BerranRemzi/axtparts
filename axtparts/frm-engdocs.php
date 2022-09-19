<?php
// ********************************************
// Copyright 2003-2023 AXT Systems Pty Limited.
// All rights reserved.
// Author: Geoff Swan
// ********************************************
// $Id: frm-engdocs.php 202 2016-07-17 06:08:05Z gswan $

// Parameters passed: 
// $pg: page number (0-n-1)
// $sc: sort category
//      0=assembly part description
//		1=engdoc description

session_start();
header("Cache-control: private");
header('Content-Type: text/html; charset=UTF-8');

include("config/config-axtparts.php");
require_once("classes/cl-axtparts.php");
$formfile = "frm-engdocs.php";
$formname = "engdocs";
$formtitle= "Assembly Documents";
$rpp = 30;

$myparts = new axtparts();

if ($myparts->SessionCheck() === false)
{
	$myparts->AlertMeTo("Session Expired.");
	$myparts->VectorMeTo(PAGE_LOGOUT);
	die();
}

$username = $myparts->SessionMeName();

if ($myparts->SessionMePrivilegeBit(TABPRIV_ASSY) !== true)
{
	$myparts->AlertMeTo("Insufficient tab privileges.");
	die();
}

$dbh = new mysqli(PARTSHOST, PARTSUSER, PARTSPASSWD, PARTSDBASE);
if ($dbh->connect_error)
{
	$myparts->AlertMeTo("Could not connect to database");
	$myparts->VectorMeTo($returnformfile);
	die();
}

$pg = 0;
if (isset($_GET['pg']))
	$pg = trim($_GET["pg"]);
if (!is_numeric($pg))
	$pg = 0;
	
$sc = 0;
if (isset($_GET['sc']))
	$sc = trim($_GET["sc"]);
if (!is_numeric($sc))
	$sc = 0;
	
// Retrieve the states for display
$dset = array();
$q_p = "select * from engdocs "
	. "\n left join assemblies on assemblies.assyid=engdocs.assyid "
	. "\n left join parts on parts.partid=assemblies.partid "
	;

// Add sorting
switch ($sc)
{
	case "0":
			$q_p .= "\n order by partdescr asc, assyrev, assyaw ";
			break;
	case "1":
			$q_p .= "\n order by engdocdescr asc ";
			break;
	default:
			$q_p .= "\n order by partdescr asc, assyrev, assyaw ";
			break;
}

// Add pagination
$q_p .= "\n limit ".$rpp." offset ".($rpp * $pg);

$s_p = $dbh->query($q_p);
$i = 0;
if ($s_p)
{
	while ($r_p = $s_p->fetch_assoc())
	{
		$dset[$i]["engdocid"] = $r_p["engdocid"];
		$dset[$i]["engdocdescr"] = $r_p["engdocdescr"];
		$dset[$i]["assydescr"] = $r_p["partdescr"]." (".$r_p["assydescr"]." - ".$r_p["partnumber"].")";
		$dset[$i]["assyrevaw"] = str_pad($r_p["assyrev"], 2, "0", STR_PAD_LEFT)."/".$r_p["assyaw"];
		$dset[$i]["filename"] = $r_p["engdocpath"];
		$dset[$i]["url"] = "../".ENGDOC_DIR."/".$r_p["partnumber"]."/".$r_p["engdocpath"];
		$dset[$i]["engdocpath"] = $r_p["engdocpath"];
		$i++;
	}
	$s_p->free();
}

// Get total number for page calculations
$q = "select count(*) as nd from engdocs";
$s = $dbh->query($q);
if ($s)
{
	$r = $s->fetch_assoc();
	$nd = $r["nd"];
	$s->free();
}
else
	$nd = $i;

$np = intval($nd/$rpp);
if (($nd % $rpp) > 0)
	$np++;

$dbh->close();

$tabparams = array();
$tabparams["tabon"] = "Assembly";
$tabparams["tabs"] = $_cfg_tabs;

$url = $formfile."?sc=".$sc."&pg=".$pg;

?>
<!DOCTYPE html>
<html lang="en-AU">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="generator" content="RSD 5.0.3519">
  <title>AXTParts</title>
  <link rel="stylesheet" href="css/vanillacss.min.css">
  <link rel="stylesheet" href="css/wireframe-theme.min.css">
  <link rel="icon" href="./images/icon-axtparts.png" type="image/png">
  <script>document.createElement( "picture" );</script>
  <script class="picturefill" async="async" src="js/picturefill.min.js"></script>
  <link rel="stylesheet" href="css/main.css">
</head>

<body>
  <div class="container container-body">
    <div class="container container-header">
      <div class="container container-head-left">
        <span class="text-element text-head-siteheading">Engineering Parts System</span>
        <span class="text-element text-head-pagetitle">Assembly Documents</span>
      </div>
      <div class="container container-head-right">
	    <button type="button" class="btn-logout" onclick="javascript:top.location.href='logout.php'">Logout</button>
        <span class="text-element text-head-user"><?php print $myparts->SessionMeName() ?></span>
      </div>
    </div>
    <?php $myparts->FormRender_Tabs($tabparams); ?>
    <div class="rule rule-formsection">
      <hr>
    </div>
    <div class="container container-contextbuttons-assy">
	  <a class="link-button btn-context" role="button" href="frm-assembly.php" title="Assemblies">Assembly</a>
	  <a class="link-button btn-context" role="button" href="frm-boms.php" title="BOMs">BOM</a>
	  <a class="link-button btn-context" role="button" href="frm-variants.php" title="Variants">Variant</a>
	  <a class="link-button btn-context-active" role="button" href="frm-engdocs.php" title="Documents">Docs</a>
	  <a class="link-button btn-context" role="button" href="frm-swbuild.php" title="SW Build">SW Build</a>
	</div>
    <div class="rule rule-formsection">
      <hr>
    </div>
    <div class="container container-pagination"><span class="text-element text-pagination-label">Page:</span>
<?php
$urlq = $formfile."?sc=".$sc;
for ($i = 0; $i < $np; $i++)
{
	if ($pg == $i)
		print "<span class=\"text-element text-pagination-num\">".($i+1)."</span>";
	else
		print "<a class=\"link-text link-pagination-num\" href=\"".$urlq."&pg=".$i."\">".($i+1)."</a>";
}
?>
    </div>
    <div class="rule rule-formsection">
      <hr>
    </div>
    <div class="container container-gridhead-docs">
      <div class="container container-gridhead-el-B0">
        <a class="link-text link-gridhead-column" href="<?php print $formfile."?sc=0&pg=".$pg ?>">Assembly</a>
      </div>
      <div class="container container-gridhead-el-B0">
        <a class="link-text link-gridhead-column" href="<?php print $formfile."?sc=1&pg=".$pg ?>">Document Description</a>
      </div>
      <div class="container container-gridhead-el-B1">
        <span class="text-element text-gridhead-column">Rev-AW</span>
      </div>
    </div>
<?php
if ($myparts->SessionMePrivilegeBit(UPRIV_ENGDOCS))
{
?>
    <div class="container container-grid-addline-docs">
      <div class="container container-grid-addline-el-B0">
        <a class="link-text link-grid-addline" href="javascript:popupOpener('pop-engdoc.php','pop_engdoc',600,600)">Add Document...</a>
      </div>
      <div class="container container-grid-addline-el-B0"></div>
      <div class="container container-grid-addline-el-B1"></div>
    </div>
<?php
}

$nd = count($dset);
for ($i = 0; $i < $nd; $i++)
{
	if ($i%2 == 0)
		$stline = "evn";
	else
		$stline = "odd";
?>
    <div class="container container-grid-data-docs">
      <div class="container container-grid-dataitem-B0-<?php print $stline ?>">
        <a class="link-text link-grid-dataitem" href="javascript:popupOpener('pop-engdoc.php?engdocid=<?php print $dset[$i]["engdocid"] ?>','pop_engdoc',600,600)" title="View/Edit engineering document detail"><?php print htmlentities($dset[$i]["assydescr"]) ?></a>
      </div>
      <div class="container container-grid-dataitem-B0-<?php print $stline ?>">
        <a class="link-text link-grid-dataitem" href="javascript:popupOpener('pop-engdocdl.php?engdocid=<?php print $dset[$i]["engdocid"] ?>','pop_engdocdl',600,600)" title="Download <?php print htmlentities($dset[$i]["engdocpath"]) ?>"><?php print htmlentities($dset[$i]["engdocdescr"]) ?></a>
      </div>
      <div class="container container-grid-dataitem-B1-<?php print $stline ?>">
        <span class="text-element text-grid-dataitem"><?php print htmlentities($dset[$i]["assyrevaw"]) ?></span>
      </div>
    </div>
<?php
}
?>
    <div class="container container-footer">
      <span class="text-element text-footer-copyright"><?php print SYSTEMBRANDING.": ".ENGPARTSVERSION ?></span>
    </div>
  </div>
  <script src="js/jquery.min.js"></script>
  <script src="js/outofview.js"></script>
  <script src="js/js-forms.js"></script>
</body>

</html>