<?php

error_reporting(E_ERROR | E_PARSE);

// this is where we catch variables sent to this page.
$dir = filter_var($_REQUEST['dir'],FILTER_SANITIZE_STRING);
$thumb = filter_var($_REQUEST['thumb'],FILTER_SANITIZE_STRING);

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
$workdir = getcwd(); // get current working dir with no trailing '/'
//$webdir = $_SERVER['SERVER_NAME'].$thisfilepath; // removed the server name to make links more... relative!
$webdir = $thisfilepath;

// version control
$version = 1.0;
$version_url_check = "https://raw.githubusercontent.com/pascalbrax/psg/master/latest_version";
$version_update = "https://github.com/pascalbrax/psg";

if ($version_online = file_get_contents($version_url_check)) {
	if ($version_online > $version) {
		echo "<div style='font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial'>
		<a href='$version_update'>New version available.</a>
		</div>
		<br />";
	}
}


// video support
$video = false;

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
if (file_exists("psimplebox.js")) {
	// add jquery script src and simplebox to html
	$htmlscripts = "<script src=\"http://ajax.googleapis.com/ajax/libs/jquery/2.0.2/jquery.min.js\"></script>\n
	<script src=\"psimplebox.js\"></script>\n";
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
            
            // if image is PNG, convert to JPEG first
            if (strpos(strtolower($thumb),".png")) { 
                $image = imagecreatefrompng($imagefilename);
                $convertedimagefilename = $cachefolder."/png2jpeg".str_replace("/","+",$dir)."+".$thumb;
                imagejpeg($image, $convertedimagefilename, 20);
                $imagefilename = $convertedimagefilename;
            }
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

	<!-- 

		If you like this gallery script, you can download it here: $version_update
	
	-->

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
	div.file:hover {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;background-color:#FAFAFA;float:left;width:200px;height:200px;text-align:center;margin-bottom:10px; margin-right:10px;
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
	if(is_file($dir_path.$entry) AND ((strpos(strtolower($entry),".jpg") OR strpos(strtolower($entry),".jpeg") OR strpos(strtolower($entry),".png")) AND !strpos(strtolower($entry),".filepart")))  {
		print '<div class="file">';
		print "<a href='".get_file($dir_path.$entry)['link']."' class='simplebox'>";
		print "<img alt='$entry' src='".$thisfilename."?dir=$dir&thumb=$entry"."'><br>";
		
		print substr($entry,0,15); // filename up to 15 chars
		print " (".human_filesize(get_file($dir_path.$entry)['size']).")</a>";
		print "</div>\n";
	  	}

	  if(is_file($dir_path.$entry) AND (strpos(strtolower($entry),".mp4") AND !strpos(strtolower($entry),".filepart")) AND $video)  {
		print '<div class="file">';
		
		print "	<video width='200' height='160'>
					<source src='".get_file($dir_path.$entry)['link']."' type='video/mp4'>
					No video support
				</video>";
		print "<a href='".get_file($dir_path.$entry)['link']."' class='simpleboxvideo'>";
		
		print substr($entry,0,15); // filename up to 15 chars
		print " (".human_filesize(get_file($dir_path.$entry)['size']).")</a>";
		print "</div>\n";
		}
		  
	}

  print "</div>";
  }
  
 
$bodyend = "</body></html>";
print $bodyend;
 

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


function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
  } 


function generate_thumb($filename) {

	// Set a maximum height and width
	$width = 200;
	$height = 160;

	// read EXIF data
	$exif = exif_read_data($filename);
	
	// crea $image dal file
	$image = imagecreatefromjpeg($filename);
	
	// rotate image if needed
	if (!empty($exif['Orientation'])) {
		switch ($exif['Orientation']) {
			case 3:
				$image = imagerotate($image, 180, 0);
			break;

			case 6:
				$image = imagerotate($image, -90, 0);
			break;

			case 8:
				$image = imagerotate($image, 90, 0);
			break;
		}
	} 
	
	// Get new dimensions
	$width_orig = imagesx($image);
	$height_orig = imagesy($image);

	$ratio_orig = $width_orig/$height_orig;

	if ($width/$height > $ratio_orig) {
		$width = $height*$ratio_orig;
		} 
	else {
		$height = $width/$ratio_orig;
	}

	// create empty image
	$image_p = imagecreatetruecolor($width, $height);
	
	// fill empty image with cropped original
	imagecopyresampled($image_p, $image, 0, 0, 0, 0, $width, $height, $width_orig, $height_orig);

	// Output
	return $image_p;
	}
	
function add_icon($type) {
	if ($type == "nav") {
		$icon = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAYAAABWdVznAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAHBJREFUeNqU0TEKwCAUA9D2Sp5AXUQQ3cSbewgHN8Ep8tdqaRr44+NDcgO4fkXA87TWcM6BBtZajDHO6A1IBIUQQANJaw0xRtBgQwyQ1FqhlAIFeu/w3nMftra+WkopcS3NOZFz5nYwxqCUclx6CTAAwWgxaW7qSDsAAAAASUVORK5CYII%3D";
		}
	if ($type == "dir") {
		//$icon = "data:image/png;base64,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";
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
