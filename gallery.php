<?php

error_reporting(E_ERROR | E_PARSE);

// version control and notification 1/2
$version = 1.10;
$version_url_check = "https://raw.githubusercontent.com/pascalbrax/psg/master/latest_version";
$version_update = "https://github.com/pascalbrax/psg";

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
$thisfilelocation = $_SERVER['PHP_SELF']; // result: /dir/thisfile.php 
$thisfilename = pathinfo($thisfilelocation)['basename']; //  result: thisfile.php
$thisfilepath = str_replace($thisfilename, "",$thisfilelocation); //  result: /dir/
$workdir = getcwd(); // get current working dir with no trailing '/' 
$webdir = $thisfilepath;

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
	<script src=\"".$thisfilepath."psimplebox.js\"></script>\n"; 
	}

if ($thumb) {
	// if we need a thumbnail	
	
	// generate headers 
	$expire=60*60*24*1; // seconds, minutes, hours, days 
	header('Pragma: public');
	header('Cache-Control: maxage='.$expire);
	header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expire) . ' GMT');
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' CET');
	header("Content-Type: image/jpeg");

	// generate image file path
	$imagefilename = $workdir.$dir."/".$thumb;
	
	// check if there is a cache folder
	if ($cache) {
		// check if the image thumb is available in cache
		$cachedfilename = $cachefolder."/thumb".str_replace("/","+",$dir)."+".$thumb;
		
		if (file_exists($cachedfilename)) {
			// read thumbnail from cache
			readfile($cachedfilename);
		} else {
			// cache available, but file not exist yet so we create it
			imagejpeg(generate_thumb($imagefilename),$cachedfilename);
            readfile($cachedfilename);
		}

	} else {
		// cache not available, generate_thumb on the fly
		
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
		background-color: #666699;
		}	
	div.nav {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:medium;background-color:#a3a3c2;margin-bottom:10px;
		}
	div.nav:hover {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:medium;background-color:#c2c2d6;margin-bottom:10px;
		}
	div.dir {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:medium;background-color:#a3a3c2;float:left;width:200px;height:180px;text-align:center;margin-bottom:15px; margin-right:10px;
		}
	div.dir:hover {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:medium;background-color:#c2c2d6;float:left;width:200px;height:180px;text-align:center;margin-bottom:15px; margin-right:10px;
		}
	div.file {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:small;background-color:#a3a3c2;float:left;width:200px;height:180px;text-align:center;margin-bottom:15px; margin-right:10px;
		}
	div.file:hover {
		color:black;font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial;font-size:small;background-color:#c2c2d6;float:left;width:200px;height:180px;text-align:center;margin-bottom:15px; margin-right:10px;
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


// version control and notification 2/2

if ($version_online = file_get_contents($version_url_check)) {
	if ($version_online > $version) {
		echo "<div style='font-family:Trebuchet MS,Tahoma,Helvetica,Verdana,Arial'>
		<a href='$version_update'>New version available.</a>
		</div>
		<br />";
	}
}

// echo "<pre> version online: $version_online local version: $version</pre>"; // debug version


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
      
	  print '<div class="dir">';
      print '<a href="'.$thisfilename.'?dir='.urlencode($dir."/".$entry).'">';
		print "<img alt='$entry' src='".add_icon("dir")."'><br>";
		print substr($entry,0,18);
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
		
		print substr($entry,0,18); // filename up to 18 chars
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
	if (strpos(strtolower($filename),".png")) { 
		$image = imagecreatefrompng($filename); // PNG
	} else {
		$image = imagecreatefromjpeg($filename); // JPG
	}
	
	
	
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

	// destroy old image 
	imagedestroy($image);

	// Output
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

/* pascal brax 2014-2021 */
  
?>
