<?php

class BookGluttonEpubFileFinder {

 /**

	Will use the system find command to index a directory, all its subdirs, and
	the contents of any zip archives, to create a searchable listing of filenames

*/


	public function __construct($repo, $findcmd="/usr/bin/find", $exp='*.epub')
	{
	
		// pass in repo, an absolute filepath on your system
		
		$this->repo = $repo;
		$this->epubfiles = array();
		$this->zipfiles = array();
		$this->basefindcmd = $findcmd;
		$this->findcmd = $this->basefindcmd;
		
		if(file_exists($this->basefindcmd)) {
			
			

				echo "Creating index of epub files\n";
				$epubfindcmd =  $this->basefindcmd." ".$this->repo." -name '".$exp."' -printf \"%T@ %p\n\"";
				error_log($epubfindcmd);
				
				exec($epubfindcmd, $this->epubfiles);
				rsort($this->epubfiles); // sort by newest first
				echo "indexed ".count($this->epubfiles)." epub files.\n";
				
				
				
				echo "Creating index of zipfiles\n";
				$zipfindcmd = $this->basefindcmd." ".$this->repo." -iname '*.zip' -printf \"%T@ %p\n\"";				
				error_log($zipfindcmd);	
				$zips = array();
				exec($zipfindcmd, $zips);
				rsort($zips); // sort by most recent first
				foreach($zips as $zip) {
					$parts = explode(' ', $zip);
					$zipfile = $parts[1];
					$zl = new OreillyZipListing($zipfile);
					$this->zipfiles[$zipfile] = $zl->getFiles();
				}
				echo "indexed ".count($this->zipfiles)." zip archive files.\n";

		} else {

		   throw new Exception("No find command found, cannot index files");
		
		}
		

	}

	public function findFileMatch($fileext)
	{
		// will find most recent epub file in repo
		$epubfiles = $this->epubfiles;
		$match = null;
		foreach($epubfiles as $file) {
			$parts = explode(' ', $file);
			//echo pathinfo($parts[1],PATHINFO_BASENAME)."==".$fileext."\n"; 
			if(pathinfo($parts[1],PATHINFO_BASENAME)==$fileext) {
				$match = $parts[1];
				break;
			}
		}
		return $match;

	}


	public function findFileMatchInZips($fileext)
	{
		$zipfiles = $this->zipfiles;
	  $match = null;
		// get a matching list with each line prefixed with unix timestamp
		foreach($zipfiles as $zipfile=>$files)
		{
			foreach($files as $file) {
				//echo pathinfo($file['name'],PATHINFO_BASENAME)."==".$fileext."\n"; 
				if(pathinfo($file['name'],PATHINFO_BASENAME)==$fileext) {
					$match = 'zip://' . $zipfile . '#'.$file['name'];
					break;
				}
			}
		}
	  return $match;
	}

	public function dumpFileListings()
	{
		print_r($this->zipfiles);
		print_r($this->epubfiles);
	}




}

?>