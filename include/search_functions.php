<?
# Search functions
# Functions to perform searches (read only)
#  - For resource indexing / keyword creation, see resource_functions.php

if (!function_exists("do_search")) {
function do_search($search,$restypes="",$order_by="relevance",$archive=0,$fetchrows=-1)
	{
	# Takes a search string $search, as provided by the user, and returns a results set
	# of matching resources.
	# If there are no matches, instead returns an array of suggested searches.
	# $restypes is optionally used to specify which resource types to search.
	
	# resolve $order_by to something meaningful in sql
	$orig_order=$order_by;
	$order=array("relevance"=>"score desc, user_rating desc, hit_count desc, creation_date desc","popularity"=>"user_rating desc,hit_count desc,creation_date desc","rating"=>"rating desc, user_rating desc, score desc","date"=>"creation_date desc","colour"=>"has_image desc,image_blue,image_green,image_red,creation_date","country"=>"country");
	$order_by=$order[$order_by];
	$keywords=split_keywords($search);
	$search=trim($search); # remove any trailing or leading spaces
	
	# -- Build up filter SQL that will be used for all queries

	$filter="";
	# append resource type filtering
	if ($restypes!="")
		{
		if ($filter!="") {$filter.=" and ";}
		$filter.="resource_type in ($restypes)";
		}
	
	# append "use" access rights, do not show restricted resources unless admin
	if (!checkperm("v"))
		{
		if ($filter!="") {$filter.=" and ";}
		$filter.="r.access<>'2'";
		}
		
	# append archive searching (don't do this for collections, archived resources can still appear in collections)
	if (substr($search,0,11)!="!collection")
		{
		global $pending_review_visible_to_all;
		if ($archive==0 && $pending_review_visible_to_all)
			{
			# If resources pending review are visible to all, when listing only live resources include
			# pending review (-1) resources too.
			if ($filter!="") {$filter.=" and ";}
			$filter.="(archive='0' or archive=-1)";
			}
		else
			{
			# Append normal filtering.
			if ($filter!="") {$filter.=" and ";}
			$filter.="archive='$archive'";
			}
		}
	
	
	# append ref filter - never return the batch upload template (negative refs)
	if ($filter!="") {$filter.=" and ";}
	$filter.="r.ref>0";
	
	# ------ Advanced 'custom' permissions, need to join to access table.
	$custperm="";
	if (!checkperm("v"))
		{
		global $usergroup;
		#$custperm=" join resource_custom_access rca on (r.access<>3 and rca.resource=0) or (r.ref=rca.resource and rca.usergroup='$usergroup' and rca.access<>2) ";
		$custperm=" left outer join resource_custom_access rca on r.ref=rca.resource and rca.usergroup='$usergroup' and rca.access<>2 ";
		if ($filter!="") {$filter.=" and ";}
		# If rca.resource is null, then no matching custom access record was found
		# If r.access is also 3 (custom) then the user is not allowed access to this resource.
		# Note that it's normal for null to be returned if this is a resource with non custom permissions (r.access<>3).
		$filter.=" not(rca.resource is null and r.access=3)";
		}
	
	# ------ Search filtering: If search_filter is specified on the user group, then we must always apply this filter.
	global $usersearchfilter;
	$sf=explode(";",$usersearchfilter);
	if (strlen($usersearchfilter)>0)
		{
		for ($n=0;$n<count($sf);$n++)
			{
			$s=explode("=",$sf[$n]);
			if (count($s)!=2) {exit ("Search filter is not correctly configured for this user group.");}

			# Find field(s) - multiple fields can be returned to support several fields with the same name.
			$f=sql_array("select ref value from resource_type_field where name='" . escape_check($s[0]) . "'");
			if (count($f)==0) {exit ("Field(s) with short name '" . $s[0] . "' not found in user group search filter.");}
			
			# Find keyword(s)
			$ks=explode("|",strtolower(escape_check($s[1])));
			$k=sql_array("select ref value from keyword where keyword in ('" . join("','",$ks) . "')");
			if (count($k)==0) {exit ("At least one of keyword(s) '" . join("', '",$ks) . "' not found in user group search filter.");}
					
			$custperm.=" join resource_keyword filter" . $n . " on r.ref=filter" . $n . ".resource and filter" . $n . ".resource_type_field in ('" . join("','",$f) . "') and filter" . $n . ".keyword in ('" . join("','",$k) . "') ";	
			}
		}
	
	# Can only search for resources that belong to themes
	if (checkperm("J"))
		{
		$custperm.=" join collection_resource jcr on jcr.resource=r.ref join collection jc on jcr.collection=jc.ref and length(jc.theme)>0 ";
		}
		
	# ------ Special searches ------
	# View Last
	if (substr($search,0,5)=="!last") 
		{
		if ($orig_order=="relevance") {$order_by="ref desc";}

		return sql_query("select *,r2.hit_count score from (select r.* from resource r $custperm where $filter order by ref desc limit " . str_replace("!last","",$search) . ") r2 order by $order_by",false,$fetchrows);
		}
	
	# View Resources With No Downloads
	if (substr($search,0,12)=="!nodownloads") 
		{
		if ($orig_order=="relevance") {$order_by="ref desc";}

		return sql_query("select *,hit_count score from resource r $custperm where $filter and ref not in (select distinct object_ref from daily_stat where activity_type='Resource download') order by $order_by",false,$fetchrows);
		}
	
	# Duplicate Resources (based on file_checksum)
	if (substr($search,0,11)=="!duplicates") 
		{
		return sql_query("select *,r.hit_count score from resource r $custperm where $filter and file_checksum in (select file_checksum from (select file_checksum,count(*) dupecount from resource group by file_checksum) r2 where r2.dupecount>1) order by file_checksum",false,$fetchrows);
		}
	
	# View Collection
	if (substr($search,0,11)=="!collection")
		{
		if ($orig_order=="relevance") {$order_by="c.date_added desc";}
		$colcustperm=$custperm;
		if (getval("k","")!="") {$colcustperm="";$filter="ref>0";} # Special case if a key has been provided.
		#echo "<!--select r.*,r.hit_count score from resource r join collection_resource c on r.ref=c.resource $colcustperm where c.collection='" . str_replace("!collection","",$search) . "' and $filter group by r.ref order by $order_by;-->";
		return sql_query("select r.*,c.*,r.hit_count score,length(c.comment) commentset from resource r join collection_resource c on r.ref=c.resource $colcustperm where c.collection='" . str_replace("!collection","",$search) . "' and $filter group by r.ref order by $order_by;",false,$fetchrows);
		}
	
	# View Related
	if (substr($search,0,8)=="!related") return sql_query("select r.*,r.hit_count score from resource r join resource_related t on ((t.related=r.ref and t.resource='" . str_replace("!related","",$search) . "') or (t.resource=r.ref and t.related='" . str_replace("!related","",$search) . "')) $custperm where 1=1 and $filter group by r.ref order by $order_by;",false,$fetchrows);
	
	# Similar to a colour
	if (substr($search,0,4)=="!rgb")
		{
		$rgb=explode(":",$search);$rgb=explode(",",$rgb[1]);
		return sql_query("select r.*,r.hit_count score from resource r $custperm where has_image=1 and $filter group by r.ref order by (abs(image_red-" . $rgb[0] . ")+abs(image_green-" . $rgb[1] . ")+abs(image_blue-" . $rgb[2] . ")) asc limit 500;",false,$fetchrows);
		}
		
	# Similar to a colour by key
	if (substr($search,0,10)=="!colourkey")
		{
		return sql_query("select r.*,r.hit_count score from resource r $custperm where has_image=1 and left(colour_key,4)='" . substr(str_replace("!colourkey","",$search),0,4) . "' and $filter group by r.ref",false,$fetchrows);
		}
	
	global $config_search_for_number;
	if ($config_search_for_number)
	{
		# Searching for a number - return just the matching resource
		if (is_numeric($search)) 
			{
			return sql_query("select r.*,r.hit_count score from resource r $custperm where ref='$search' and $filter group by r.ref");
			}
	}
	
	# Searching for pending archive
	if ($search=="!archivepending")
		{
		return sql_query("select r.*,r.hit_count score from resource r $custperm where archive=1 and ref>0 group by r.ref order by $order_by",false,$fetchrows);
		}
	
	if ($search=="!userpending")
		{
		if ($order_by="user_rating desc,hit_count desc,creation_date desc") {$order_by="request_count desc," . $order_by;}
		return sql_query("select r.*,r.hit_count score from resource r $custperm where archive=-1 and ref>0 group by r.ref order by $order_by",false,$fetchrows);
		}
		
	# View Contributions
	if (substr($search,0,14)=="!contributions") 
		{
		global $userref;
		if ($userref==str_replace("!contributions","",$search)) {$filter="1=1";$custperm="";} # Disable permissions when viewing your own contributions
		return sql_query("select r.*,r.hit_count score from resource r $custperm where created_by='" . str_replace("!contributions","",$search) . "' and $filter group by r.ref order by $order_by",false,$fetchrows);
		}
	
	# Search for resources with images
	if ($search=="!images") return sql_query("select r.*,r.hit_count score from resource r $custperm where has_image=1 group by r.ref order by $order_by",false,$fetchrows);
	
	$suggested=$keywords; # a suggested search
	$fullmatch=true;
	$sql="";$c=0;$t="";$t2="";$score="";
	for ($n=0;$n<count($keywords);$n++)
		{
		$keyword=$keywords[$n];
		$field=0;#echo "<li>$keyword<br/>";
		if (strpos($keyword,":")!==false)
			{
			$k=explode(":",$keyword);
			if ($k[0]=="day")
				{
				if ($sql!="") {$sql.=" and ";}
				$sql.="r.creation_date like '____-__-" . $k[1] . "%' ";
				}
			elseif ($k[0]=="month")
				{
				if ($sql!="") {$sql.=" and ";}
				$sql.="r.creation_date like '____-" . $k[1] . "-%' ";
				}
			elseif ($k[0]=="year")
				{
				if ($sql!="") {$sql.=" and ";}
				$sql.="r.creation_date like '" . $k[1] . "-%' ";
				}
			else
				{
				$ckeywords=explode(";",$k[1]);
				$field=sql_value("select ref value from resource_type_field where name='" . escape_check($k[0]) . "'",0);
				
				$c++;
				$t.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and k" . $c . ".resource_type_field='" . $field . "'";
						
				if ($score!="") {$score.="+";}
				$score.="k" . $c . ".hit_count";
				
				# work through all options in an OR approach for multiple selects on the same field
				# where k.resource=type_field=$field and (k*.keyword=3 or k*.keyword=4) etc
				$t.=" and (";
				for ($m=0;$m<count($ckeywords);$m++)
					{
					$keyref=resolve_keyword($ckeywords[$m]);
					if ($m!=0) {$t.=" or ";}
					$t.="k" . $c. ".keyword='$keyref'";
					
					# Log this
					daily_stat("Keyword usage",$keyref);
	
					# Also add related.
					$related=get_related_keywords($keyref);
					for ($m=0;$m<count($related);$m++)
						{
						$t.=" or k" . $c . ".keyword='" . $related[$m] . "'";
						}

					}
				$t.=")";
				}
			}
		else
			{
			global $noadd;
			if (!in_array($keyword,$noadd)) # skip common words that are excluded from indexing
				{
				$keyref=resolve_keyword($keyword);
				if ($keyref==false)
					{
					$fullmatch=false;
					$soundex=resolve_soundex($keyword);
					if ($soundex===false)
						{
						# No keyword match, and no keywords sound like this word. Suggest dropping this word.
						$suggested[$n]="";
						}
					else
						{
						# No keyword match, but there's a word that sounds like this word. Suggest this word instead.
						$suggested[$n]="<i>" . $soundex . "</i>";
						}
					}
				else
					{
					# Key match, add to query.
					$c++;
					if ($sql!="") {$sql.=" and ";}

					# Add related keywords
					$related=get_related_keywords($keyref);$relatedsql="";
					for ($m=0;$m<count($related);$m++)
						{
						$relatedsql.=" or k" . $c . ".keyword='" . $related[$m] . "'";
						}
					
					$t.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and (k" . $c . ".keyword='$keyref' $relatedsql)";
					
					if ($score!="") {$score.="+";}
					$score.="k" . $c . ".hit_count";
					
					# Log this
					daily_stat("Keyword usage",$keyref);
					}
				}
			}
		}
	if ($fullmatch==false)
		{
		if ($suggested==$keywords)
			{
			# Nothing different to suggest.
			return "";
			}
		else
			{
			# Suggest alternative spellings/sound-a-likes
			$suggest="";
			if (strpos($search,",")===false) {$suggestjoin=" ";} else {$suggestjoin=", ";}
			for ($n=0;$n<count($suggested);$n++)
				{
				if ($suggested[$n]!="")
					{
					if ($suggest!="") {$suggest.=$suggestjoin;}
					$suggest.=$suggested[$n];
					}
				}
			return $suggest;
			}
		}
	
	if ($filter!="")
		{
		if ($sql!="") {$sql.=" and ";}
		$sql.=$filter;
		}

	# Append custom permissions	
	$t.=$custperm;
	
	if ($score=="") {$score="r.hit_count";} # In case score hasn't been set (i.e. empty search)
	global $max_results;
	if (($t2!="") && ($sql!="")) {$sql=" and " . $sql;}
	
	# Compile final SQL
	$sql="select distinct r.*,$score score from resource r" . $t . " where $t2 $sql group by r.ref order by $order_by limit $max_results";

	# Execute query
	$result=sql_query($sql,false,$fetchrows);
	if (count($result)>0) {return $result;}
	
	# (temp) - no suggestion for field-specific searching for now - TO DO: modify function below to support this
	if (strpos($search,":")!==false) {return "";}
	
	# All keywords resolved OK, but there were no matches
	# Remove keywords, least used first, until we get results.
	$sql="";
	for ($n=0;$n<count($keywords);$n++)
		{
		if ($sql!="") {$sql.=" or ";}
		$sql.="keyword='" . $keywords[$n] . "'";
		}
	$least=sql_value("select keyword value from keyword where $sql order by hit_count asc limit 1","");
	return trim_spaces(str_replace(" " . $least . " "," "," " . join(" ",$keywords) . " "));
	}
}


function resolve_soundex($keyword)
	{
	# returns the most commonly used keyword that sounds like $keyword, or failing a soundex match,
	# the most commonly used keyword that starts with the same few letters.
	$soundex=sql_value("select keyword value from keyword where soundex=soundex('$keyword') order by hit_count desc limit 1",false);
	if (($soundex===false) && (strlen($keyword)>=4))
		{
		# No soundex match, suggest words that start with the same first few letters.
		return sql_value("select keyword value from keyword where keyword like '" . substr($keyword,0,4) . "%' order by hit_count desc limit 1",false);
		}
	return $soundex;
	}
	
function suggest_refinement($refs,$search)
	{
	# Given an array of resource references ($refs) and the original
	# search query ($search), produce a list of suggested search refinements to 
	# reduce the result set intelligently.
	$in=join(",",$refs);
	$suggest=array();
	# find common keywords
	$refine=sql_query("select k.keyword,count(*) c from resource_keyword r join keyword k on r.keyword=k.ref and r.resource in ($in) and length(k.keyword)>=3 and length(k.keyword)<=15 and k.keyword not like '%0%' and k.keyword not like '%1%' and k.keyword not like '%2%' and k.keyword not like '%3%' and k.keyword not like '%4%' and k.keyword not like '%5%' and k.keyword not like '%6%' and k.keyword not like '%7%' and k.keyword not like '%8%' and k.keyword not like '%9%' group by k.keyword order by c desc limit 5");
	for ($n=0;$n<count($refine);$n++)
		{
		if (strpos($search,$refine[$n]["keyword"])===false)
			{
			$suggest[]=$search . " " . $refine[$n]["keyword"];
			}
		}
	return $suggest;
	}
	
function get_advanced_search_fields($archive=false)
	{
	# Returns a list of fields suitable for advanced searching.	
	$return=array();
	$fields=sql_query("select * from resource_type_field where advanced_search=1 and keywords_index=1 and length(name)>0 " . (($archive)?"":"and resource_type<>999") . " order by resource_type,order_by");
	for ($n=0;$n<count($fields);$n++)
		{
		if ((checkperm("f*") || checkperm("f" . $fields[$n]["ref"]))
		&& !checkperm("f-" . $fields[$n]["ref"]))
		{$return[]=$fields[$n];}
		}
	return $return;
	}

	
	
?>
