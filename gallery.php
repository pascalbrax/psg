<?php

error_reporting(E_ERROR | E_PARSE);

// this is where we catch variables sent to this page.
$dir = $_REQUEST['dir'];
$thumb = $_REQUEST['thumb'];

// fix stuff & block directory traversal
if (strstr($dir,"..") OR ($dir == "/")) {
  $dir = "";
  }
if (strstr($thumb,"..") OR ($thumb == "/")) {
  unset ($thumb);
  }

// autodiscover some stuff...
$thisfilelocation = $_SERVER['PHP_SELF'];
$thisfilename = pathinfo($thisfilelocation)['basename'];
$thisfilepath = str_replace($thisfilename, "",$thisfilelocation);

$workdir = getcwd(); // no trailing '/'
$webdir = $_SERVER['SERVER_NAME'].$thisfilepath;

// add jquery script src to html 
$htmlscripts = "<script src=\"http://ajax.googleapis.com/ajax/libs/jquery/2.0.2/jquery.min.js\"></script>\n";

// check if cache folder is available
$cachefolder = "cache"; // folder name where cache images thumbnails
$cache = false;
if (file_exists($cachefolder)) {
	// cache folder is available
	if (is_writable($cachefolder)) {
		// cache folder is writable
		$cache = true;
		}
	}

// enable psimplebox (lightbox clone)
$lightbox = true;
if ($lightbox) {
	$htmlscripts .= "<script src=\"http://mira.scavenger.ch/~pascal/pslb/psimplebox.js\"></script>\n";
	}

if ($thumb) {
	// if we need a thumbnail	
		
	$expire=60*60*24*1; // seconds, minutes, hours, days
  
	header('Pragma: public');
	header('Cache-Control: maxage='.$expire);
	header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expire) . ' GMT');
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' CET');
	header("Content-Type: image/jpeg");
	// see if there is a cache folder
	$imagefilename = $workdir.$dir."/$thumb";
	if ($cache) {
		// check if the image thumb is available in cache
		$cachedfilename = $cachefolder."/thumb".str_replace("/","+",$dir)."+".$thumb;
		if (file_exists($cachedfilename)) {
			// read thumbnail from cache
			readfile($cachedfilename);
			
			}
		else {
			// cache available, file not exist yet so we create it
			imagejpeg(generate_thumb($imagefilename),$cachedfilename);
			readfile($cachedfilename);
			}
		}
	else {
		// cache not available, generate_thumb on the fly
		//print "<pre>"; print_r(get_defined_vars()); print "</pre>";
		imagejpeg(generate_thumb($imagefilename));
		}
	exit();
	}

	
// start webpage
$htmlstart = "
<!DOCTYPE html>
<html>
<head>
	<title>pSimpleGallery $dir</title>
	<style>
	body {
		background-color: #DDDDDD;
		}	
	div.nav {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;background-color:#EEEEEE;margin-bottom:20px;
		}
	div.file {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;background-color:#EEEEEE;float:left;width:200px;height:200px;text-align:center;margin-bottom:10px; margin-right:10px;
		}
	div.size {
		font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;background-color: #CCCCFF;
		}
	div.date {
		font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;background-color: #DDDDFF;
		}

	a:link {color:black;text-decoration:none;}
	a:visited {color:black;text-decoration:none;}
	a:hover {color:blue;text-decoration:none;}
	a:active {color:black;text-decoration:none;}
	</style>
	
	$htmlscripts
</head>
<body>";
print $htmlstart;

// This merge together the base directory and the user's requested folder.
$fulldir = $workdir.$dir;

// path tree
if ($handle = opendir($fulldir)) {
  if ($dir) {
    $i = 1;
    $dirarray = explode("/",$dir);
	print "<div class='nav'>";
    foreach ($dirarray as &$folder) {
      if (!$folder) { $folder = "root"; } // human name to root
      print '<a href="?dir=';
      for($s = 0; $s < $i; $s++) {
        if ($s) { print $dirarray[$s]; }
        if ($dirarray[$s] != $folder) { print "/"; } // add '/' in the right places for the GET variables
        }
      print '">';
	  print '<img width="12" height="12" alt="'.$folder.'" src="'; // start img tag
	  print add_icon("nav"); // insert image data
	  print '" />&nbsp;'; // close img tag (and add a space)
      print "$folder";
      print "</a>";
      $i++;
      }
	print "</div>";
    }
  else { 
    print '<div class="nav"><img alt="root" width="12" height="12" src="'.add_icon("nav").'" />&nbsp;root</div>'; 
	}


  // directory list with scandir
  $dir_path = $fulldir."/";
  $exclude_list = array(".", "..",$cachefolder);
  $directories = array_diff(scandir($dir_path), $exclude_list);

  foreach($directories as $entry) {
    if(is_dir($dir_path.$entry)) {
      
	  print '<div class="file">';
      print '<a href="'.$thisfilename.'?dir='.urlencode($dir."/".$entry).'">';
		print "<img alt='$entry' src='".add_icon("dir")."'><br>";
		print substr($entry,0,15);
	  print '</a>';
	  print "</div>\n";
      }
	}
  
  // image list
  print "<div id=\"imageSet\">";
  foreach($directories as $entry) {
    if(is_file($dir_path.$entry) AND (strpos(strtolower($entry),".jpg") OR strpos(strtolower($entry),".jpeg")))  {
		print '<div class="file">';
		print "<a href='//".get_file($dir_path.$entry)['link']."' class='simplebox'>";
		print "<img alt='$entry' src='".$thisfilename."?dir=$dir&thumb=$entry"."'><br>";
		// print get_file($dir_path.$entry)['name']; // useless...
		print substr($entry,0,15);
		print " (".human_filesize(get_file($dir_path.$entry)['size']).")</a>";
		print "</div>\n";

      }
	}
  print "</div>";
  }
  
function get_file($entry) {
	global $workdir, $webdir, $dir;
	if (file_exists($entry)) {
		$name = pathinfo($entry)['basename'];
		$folder = pathinfo($entry)['dirname'];
		$fixed_dir = substr($dir,1)."/"; // move '/' from start to end
		$link = $webdir.$fixed_dir.$name;
		$size = filesize($entry);
		$updated = date ("d/m/Y", filemtime($dir_path.$entry));
		
		return compact('name','folder','link','size','updated');
		}
	else {
		return false;
		}
}
 
$bodyend = "</body></html>";
print $bodyend;
 
function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
  } 


function generate_thumb($filename) {

	// Set a maximum height and width
	$width = 200;
	$height = 160;


	// Get new dimensions
	list($width_orig, $height_orig) = getimagesize($filename);

	$ratio_orig = $width_orig/$height_orig;

	if ($width/$height > $ratio_orig) {
		$width = $height*$ratio_orig;
		} 
	else {
		$height = $width/$ratio_orig;
	}

	// Resample
	$image_p = imagecreatetruecolor($width, $height);
	$image = imagecreatefromjpeg($filename);
	imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $width_orig, $height_orig);

	// Output
	//imagejpeg($image_p, null, 100);
	return $image_p;
	}
	
function add_icon($type) {
	if ($type == "nav") {
		$icon = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAYAAABWdVznAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAHBJREFUeNqU0TEKwCAUA9D2Sp5AXUQQ3cSbewgHN8Ep8tdqaRr44+NDcgO4fkXA87TWcM6BBtZajDHO6A1IBIUQQANJaw0xRtBgQwyQ1FqhlAIFeu/w3nMftra+WkopcS3NOZFz5nYwxqCUclx6CTAAwWgxaW7qSDsAAAAASUVORK5CYII%3D";
		}
	if ($type == "dir") {
		$icon = "data:image/png;base64,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";
		}
	if ($type == "file") {
		$icon = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAYAAABWdVznAAABH0lEQVR4nI3OMU7DQBCF4X/tNZAGKm5Ag8QZOAA93CBNWrpcAYmWhpYLUKRJwQGooQYpSAGEQMHeXa+9MxRGCDsUvGak2f1mxgBM5kvlj5wf7rK1mZu1h/FsoY+vXkVVYyu6fK91PFvoZL7Ul7fQG5YBhBAZ2YwkigGMQrnyTA62Ob15onTND7IAPgQEpU7SfY6J1Yfj4blkr4Djyzv6wNXYjZy6EcAQRQgucHG7AANmRB9UlcO3qbvPGLLCcHayTytCm5Tp9f0QeFwUbAaKIc8MOyMLQGiEqvJDEAiNkGVd0wD6XauYKKvQB845XGyx+bpwdUtVugHwNT4Jhf6e3cU3Ce+GG3x3UrIQG6H08dsoRW4Jvu6D8tNxNL3iP/kC9mepySQNpKwAAAAASUVORK5CYII%3D";
		}
	if ($type == "empty") {
		$icon = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
		}	
	return $icon;
	}

/* pascal brax 2014 */
  
?>
