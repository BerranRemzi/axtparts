<?php
// ********************************************
// Copyright 2003-2023 AXT Systems Pty Limited.
// All rights reserved.
// Author: Geoff Swan
// ********************************************
// Minimal stateless JSON API for axtparts.
//
// Authentication:
//   The API does NOT use PHP sessions. Each user authenticates with
//   their login ID (header "X-API-User") and their password hash
//   (header "X-API-Token"). The hash is the SSHA1 base-64 string
//   stored in the user.passwd column — the same value shown in the
//   Add/Edit User dialog when a password is set or changed.
//
// Authorisation:
//   The API inherits the authenticated user's privilege bits from
//   their role. The API can do exactly what that user can do in the
//   web UI — nothing more, nothing less.
//
// All responses are JSON:
//   { "status": true|false, "data": ..., "error": "..." }

class axtparts_api
{
    private $dbh;
    private $myparts;
    private $uid;
    private $privilege;

    // ---------------------------------------------------------------
    // Construction / authentication
    // ---------------------------------------------------------------

    public function
    __construct($dbh)
    {
        $this->dbh = $dbh;
        $this->myparts = new axtparts();
        $this->uid = false;
        $this->privilege = 0;
    }

    /**
    * Authenticate the request using login ID + password hash.
    * Loads the user's privilege mask (from their role) so the API
    * can enforce the same per-user privileges as the web UI.
    *
    * @param string $loginid  login ID supplied by the client
    * @param string $token    password hash (SSHA1 base-64) supplied by the client
    * @return boolean true if authenticated, false otherwise
    */
    public function
    Authenticate($loginid, $token)
    {
        if (!is_string($loginid) || $loginid === "")
            return false;
        if (!is_string($token) || $token === "")
            return false;

        // Load the user row (joins role, so privilege comes from the role).
        $u = $this->myparts->UserRead($this->dbh, false, $loginid);
        if ($u["status"] !== true || !isset($u["user"][0]))
            return false;

        $user = $u["user"][0];

        // Refuse if the user is inactive or not permitted to log in.
        if (!defined("USERSTATUS_ACTIVE") || $user["status"] != USERSTATUS_ACTIVE)
            return false;
        if (!defined("UPRIV_USERLOGIN") || !($user["privilege"] & UPRIV_USERLOGIN))
            return false;

        // Constant-time comparison of the provided token against the
        // stored password hash. No plaintext password is ever needed.
        if (!hash_equals($user["passwd"], $token))
            return false;

        $this->uid = (int)$user["uid"];
        $this->privilege = (int)$user["privilege"];
        return true;
    }

    /**
    * Returns true if the API user holds the given privilege bit.
    */
    public function
    HasPrivilege($priv)
    {
        return ($this->privilege & $priv) ? true : false;
    }

    public function
    UID()
    {
        return $this->uid;
    }

    // ---------------------------------------------------------------
    // Response helpers
    // ---------------------------------------------------------------

    public function
    Ok($data = null)
    {
        return array("status" => true, "data" => $data, "error" => null);
    }

    public function
    Fail($error, $data = null)
    {
        return array("status" => false, "data" => $data, "error" => $error);
    }

    public function
    SendJSON($rv)
    {
        if (!headers_sent())
        {
            header("Content-Type: application/json; charset=UTF-8");
            header("Cache-Control: no-store, no-cache, must-revalidate");
        }
        echo json_encode($rv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /**
    * Connectivity + privilege check.
    */
    public function
    Ping()
    {
        return $this->Ok(array(
            "api"        => "axtparts",
            "version"    => defined("ENGPARTSVERSION") ? ENGPARTSVERSION : "unknown",
            "uid"        => $this->uid,
            "privileges" => $this->DescribePrivileges(),
        ));
    }

    /**
    * List parts with their current category, footprint and stock summary.
    * Supports optional filters: partcatid, search (matches partdescr or
    * partnumber), limit (default 1000, max 10000), offset (default 0).
    */
    public function
    ListParts($filters = array())
    {
        if (!$this->HasPrivilege(UPRIV_VIEWPARTS))
            return $this->Fail("Insufficient privileges.");

        $where = array();
        if (isset($filters["partcatid"]) && is_numeric($filters["partcatid"]))
            $where[] = "parts.partcatid='".$this->dbh->real_escape_string($filters["partcatid"])."'";
        if (isset($filters["search"]) && is_string($filters["search"]) && trim($filters["search"]) !== "")
        {
            $term = "%".$this->dbh->real_escape_string(trim($filters["search"]))."%";
            $where[] = "(parts.partdescr like '".$term."' or parts.partnumber like '".$term."')";
        }

        $limit  = isset($filters["limit"])  ? max(1, min(10000, (int)$filters["limit"]))  : 1000;
        $offset = isset($filters["offset"]) ? max(0, (int)$filters["offset"]) : 0;

        $q = "select parts.partid, parts.partnumber, parts.partdescr, "
           . "\n parts.partcatid, pgroups.catdescr, "
           . "\n parts.footprint, footprint.fprintdescr "
           . "\n from parts "
           . "\n left join pgroups on pgroups.partcatid=parts.partcatid "
           . "\n left join footprint on footprint.fprintid=parts.footprint ";
        if (count($where) > 0)
            $q .= "\n where ".implode(" and ", $where)." ";
        $q .= "\n order by parts.partnumber asc "
            . "\n limit ".$limit." offset ".$offset;

        $s = $this->dbh->query($q);
        if (!$s)
            return $this->Fail("Database error: ".$this->dbh->error);

        $rows = array();
        while ($r = $s->fetch_assoc())
        {
            // Attach total stock qty for convenience.
            $pid = (int)$r["partid"];
            $q_stk = "select sum(qty) as totalqty from stock where partid='".$pid."'";
            $s_stk = $this->dbh->query($q_stk);
            $r["totalstock"] = 0;
            if ($s_stk)
            {
                $r_stk = $s_stk->fetch_assoc();
                if ($r_stk["totalqty"] !== null)
                    $r["totalstock"] = (int)$r_stk["totalqty"];
                $s_stk->free();
            }
            $r["partid"]    = $pid;
            $r["partcatid"] = (int)$r["partcatid"];
            $r["footprint"] = (int)$r["footprint"];
            $rows[] = $r;
        }
        $s->free();
        return $this->Ok($rows);
    }

    /**
    * Get a single part with full details (category, footprint, stock
    * breakdown across all locations). Required: partid.
    */
    public function
    GetPart($partid)
    {
        if (!$this->HasPrivilege(UPRIV_VIEWPARTS))
            return $this->Fail("Insufficient privileges.");
        if (!is_numeric($partid))
            return $this->Fail("partid must be numeric.");

        $pid = (int)$partid;

        $q = "select parts.partid, parts.partnumber, parts.partdescr, "
           . "\n parts.partcatid, pgroups.catdescr, pgroups.datadir, "
           . "\n parts.footprint, footprint.fprintdescr "
           . "\n from parts "
           . "\n left join pgroups on pgroups.partcatid=parts.partcatid "
           . "\n left join footprint on footprint.fprintid=parts.footprint "
           . "\n where parts.partid='".$this->dbh->real_escape_string($pid)."' ";
        $s = $this->dbh->query($q);
        if (!$s)
            return $this->Fail("Database error: ".$this->dbh->error);
        $r = $s->fetch_assoc();
        $s->free();
        if (!$r)
            return $this->Fail("Part not found.");

        $r["partid"]    = (int)$r["partid"];
        $r["partcatid"] = (int)$r["partcatid"];
        $r["footprint"] = (int)$r["footprint"];

        // Attach stock breakdown.
        $q_stk = "select stock.stockid, stock.qty, stock.note, "
               . "\n stock.locid, locn.locref, locn.locdescr "
               . "\n from stock "
               . "\n left join locn on locn.locid=stock.locid "
               . "\n where stock.partid='".$this->dbh->real_escape_string($pid)."' "
               . "\n order by locn.locref asc ";
        $s_stk = $this->dbh->query($q_stk);
        $stockrows = array();
        $totalstock = 0;
        if ($s_stk)
        {
            while ($r_stk = $s_stk->fetch_assoc())
            {
                $r_stk["stockid"] = (int)$r_stk["stockid"];
                $r_stk["locid"]   = (int)$r_stk["locid"];
                $r_stk["qty"]     = (int)$r_stk["qty"];
                $totalstock += $r_stk["qty"];
                $stockrows[] = $r_stk;
            }
            $s_stk->free();
        }
        $r["stock"]      = $stockrows;
        $r["totalstock"] = $totalstock;

        return $this->Ok($r);
    }

    /**
    * Create a new part. Generates the part number automatically using the
    * same CalcPartNumber algorithm as the web UI.
    * Required: partdescr. Optional: partcatid (default 0), footprint (default 0).
    * Returns the new partid and partnumber.
    */
    public function
    CreatePart($partdescr, $partcatid = 0, $footprint = 0)
    {
        if (!$this->HasPrivilege(UPRIV_PARTS))
            return $this->Fail("Insufficient privileges.");
        if (!is_string($partdescr) || trim($partdescr) === "")
            return $this->Fail("partdescr is required.");

        $partdescr = trim($partdescr);
        $partcatid = is_numeric($partcatid) ? (int)$partcatid : 0;
        $footprint = is_numeric($footprint) ? (int)$footprint : 0;

        // If a category was specified, verify it exists.
        if ($partcatid !== 0)
        {
            $nc = $this->myparts->ReturnCountOf($this->dbh, "pgroups", "partcatid", "partcatid", $partcatid);
            if ($nc == 0)
                return $this->Fail("Category ".$partcatid." does not exist.");
        }

        // If a footprint was specified, verify it exists.
        if ($footprint !== 0)
        {
            $nf = $this->myparts->ReturnCountOf($this->dbh, "footprint", "fprintid", "fprintid", $footprint);
            if ($nf == 0)
                return $this->Fail("Footprint ".$footprint." does not exist.");
        }

        $q = "insert into parts "
           . "\n set "
           . "\n partdescr='".$this->dbh->real_escape_string($partdescr)."', "
           . "\n partcatid='".$this->dbh->real_escape_string($partcatid)."', "
           . "\n footprint='".$this->dbh->real_escape_string($footprint)."' ";
        $s = $this->dbh->query($q);
        if (!$s)
            return $this->Fail("Database error: ".$this->dbh->error);

        $newpartid = (int)$this->dbh->insert_id;
        $partnum = $this->myparts->CalcPartNumber(
            str_pad($newpartid, 6, "0", STR_PAD_LEFT), PARTPREFIX);

        $q2 = "update parts "
            . "\n set partnumber='".$this->dbh->real_escape_string($partnum)."' "
            . "\n where partid='".$this->dbh->real_escape_string($newpartid)."' ";
        $s2 = $this->dbh->query($q2);
        if (!$s2)
            return $this->Fail("Database error on partnumber update: ".$this->dbh->error);

        $this->myparts->LogSave($this->dbh, LOGTYPE_PARTNEW, $this->uid,
            "API: Part created: ".$partdescr);
        return $this->Ok(array(
            "partid"     => $newpartid,
            "partnumber" => $partnum,
            "created"    => true,
        ));
    }

    /**
    * Add a stock entry (quantity of a part at a location).
    * If a stock entry already exists for the same partid + locid, the
    * qty is added to it (accumulates). Otherwise a new row is created.
    * Required: partid, locid. Optional: qty (default 1), note (default "").
    */
    public function
    AddStock($partid, $locid, $qty = 1, $note = "")
    {
        if (!$this->HasPrivilege(UPRIV_STOCK))
            return $this->Fail("Insufficient privileges.");
        if (!is_numeric($partid))
            return $this->Fail("partid must be numeric.");
        if (!is_numeric($locid))
            return $this->Fail("locid must be numeric.");
        if (!is_numeric($qty) || (int)$qty < 0)
            return $this->Fail("qty must be a non-negative integer.");

        $pid    = (int)$partid;
        $lid    = (int)$locid;
        $qtyval = (int)$qty;
        $note   = is_string($note) ? trim($note) : "";

        // Verify the part exists.
        $np = $this->myparts->ReturnCountOf($this->dbh, "parts", "partid", "partid", $pid);
        if ($np == 0)
            return $this->Fail("Part does not exist.");

        // Verify the location exists.
        $nl = $this->myparts->ReturnCountOf($this->dbh, "locn", "locid", "locid", $lid);
        if ($nl == 0)
            return $this->Fail("Location does not exist.");

        // Check if a stock entry already exists for this part+location.
        $q_find = "select stockid, qty from stock "
                . "\n where partid='".$this->dbh->real_escape_string($pid)."' "
                . "\n and locid='".$this->dbh->real_escape_string($lid)."' "
                . "\n limit 1 ";
        $s_find = $this->dbh->query($q_find);
        if (!$s_find)
            return $this->Fail("Database error: ".$this->dbh->error);

        if ($s_find->num_rows > 0)
        {
            // Accumulate into the existing entry.
            $r_find = $s_find->fetch_assoc();
            $stockid = (int)$r_find["stockid"];
            $existing_qty = (int)$r_find["qty"];
            $s_find->free();

            $newqty = $existing_qty + $qtyval;
            $q = "update stock "
               . "\n set qty='".$this->dbh->real_escape_string($newqty)."' ";
            if ($note !== "")
                $q .= ", note='".$this->dbh->real_escape_string($note)."' ";
            $q .= "\n where stockid='".$this->dbh->real_escape_string($stockid)."' ";
            $s = $this->dbh->query($q);
            if (!$s)
                return $this->Fail("Database error: ".$this->dbh->error);

            $this->myparts->LogSave($this->dbh, LOGTYPE_PARTLOCNASSIGN, $this->uid,
                "API: Stock added: +".$qtyval." to location ".$lid." for part ".$pid);
            return $this->Ok(array(
                "stockid"    => $stockid,
                "newqty"     => $newqty,
                "accumulated"=> true,
            ));
        }
        $s_find->free();

        // Create a new stock entry.
        $q = "insert into stock "
           . "\n set "
           . "\n qty='".$this->dbh->real_escape_string($qtyval)."', "
           . "\n note='".$this->dbh->real_escape_string($note)."', "
           . "\n locid='".$this->dbh->real_escape_string($lid)."', "
           . "\n partid='".$this->dbh->real_escape_string($pid)."' ";
        $s = $this->dbh->query($q);
        if (!$s)
            return $this->Fail("Database error: ".$this->dbh->error);

        $newid = (int)$this->dbh->insert_id;
        $this->myparts->LogSave($this->dbh, LOGTYPE_PARTLOCNASSIGN, $this->uid,
            "API: Stock created: ".$qtyval." at location ".$lid." for part ".$pid);
        return $this->Ok(array(
            "stockid"    => $newid,
            "newqty"     => $qtyval,
            "accumulated"=> false,
        ));
    }

    /**
    * Update an existing part's mutable attributes.
    * Required: partid. Optional (any subset): partdescr, partcatid,
    * footprint. Only the supplied fields are changed; omitted fields
    * are left untouched. partcatid/footprint may be set to 0 to clear
    * them. Returns the updated partid.
    */
    public function
    UpdatePart($partid, $fields = array())
    {
        if (!$this->HasPrivilege(UPRIV_PARTS))
            return $this->Fail("Insufficient privileges.");
        if (!is_numeric($partid))
            return $this->Fail("partid must be numeric.");

        $pid = (int)$partid;

        // Verify the part exists.
        $np = $this->myparts->ReturnCountOf($this->dbh, "parts", "partid", "partid", $pid);
        if ($np == 0)
            return $this->Fail("Part does not exist.");

        // Build the SET clause from only the supplied, recognised fields.
        $sets = array();
        $logparts = array();

        if (array_key_exists("partdescr", $fields))
        {
            $d = is_string($fields["partdescr"]) ? trim($fields["partdescr"]) : "";
            if ($d === "")
                return $this->Fail("partdescr must not be empty.");
            $sets[] = "partdescr='".$this->dbh->real_escape_string($d)."'";
            $logparts[] = "partdescr='".$d."'";
        }

        if (array_key_exists("partcatid", $fields))
        {
            $cid = is_numeric($fields["partcatid"]) ? (int)$fields["partcatid"] : 0;
            if ($cid !== 0)
            {
                $nc = $this->myparts->ReturnCountOf($this->dbh, "pgroups", "partcatid", "partcatid", $cid);
                if ($nc == 0)
                    return $this->Fail("Category ".$cid." does not exist.");
            }
            $sets[] = "partcatid='".$this->dbh->real_escape_string($cid)."'";
            $logparts[] = "partcatid=".$cid;
        }

        if (array_key_exists("footprint", $fields))
        {
            $fid = is_numeric($fields["footprint"]) ? (int)$fields["footprint"] : 0;
            if ($fid !== 0)
            {
                $nf = $this->myparts->ReturnCountOf($this->dbh, "footprint", "fprintid", "fprintid", $fid);
                if ($nf == 0)
                    return $this->Fail("Footprint ".$fid." does not exist.");
            }
            $sets[] = "footprint='".$this->dbh->real_escape_string($fid)."'";
            $logparts[] = "footprint=".$fid;
        }

        if (count($sets) === 0)
            return $this->Fail("No updatable fields supplied (partdescr, partcatid, footprint).");

        $q = "update parts "
           . "\n set ".implode(", ", $sets)." "
           . "\n where partid='".$this->dbh->real_escape_string($pid)."' ";
        $s = $this->dbh->query($q);
        if (!$s)
            return $this->Fail("Database error: ".$this->dbh->error);

        $this->myparts->LogSave($this->dbh, LOGTYPE_PARTCHANGE, $this->uid,
            "API: Part updated: ".$pid." (".implode(", ", $logparts).")");
        return $this->Ok(array(
            "partid"  => $pid,
            "updated" => true,
        ));
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private function
    DescribePrivileges()
    {
        $bits = array(
            "UPRIV_PARTS"        => UPRIV_PARTS,
            "UPRIV_PARTCATS"     => UPRIV_PARTCATS,
            "UPRIV_STOCKLOCN"    => UPRIV_STOCKLOCN,
            "UPRIV_STOCK"        => UPRIV_STOCK,
            "UPRIV_VIEWPARTS"    => UPRIV_VIEWPARTS,
            "UPRIV_USERADMIN"    => UPRIV_USERADMIN,
            "UPRIV_USERLOGIN"    => UPRIV_USERLOGIN,
        );
        $out = array();
        foreach ($bits as $name => $mask)
            $out[$name] = ($this->privilege & $mask) ? true : false;
        return $out;
    }
}