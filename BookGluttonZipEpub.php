<?php

require_once('./BookGluttonEpub.php');

class BookGluttonZipEpub extends BookGluttonEpub
{
	
	public function __construct()
	{
		$this->apache_user = 'apache';
		$this->logecho = false;
		parent::__construct();
	}
			
	public function loadZip($file)
	{
		
		$this->zipfile = $file;
		$this->_za = null;
		$this->filelist = array();
  	$this->log("reading zip file ".$file);
    $this->_openZip();
    if(!is_resource($this->ziphandle)) {
       $this->log('error '.$this->ziphandle);
			 throw new Exception('could not open zip data');
    } else {
       while($e = zip_read($this->ziphandle)) {   
          $this->filelist[] = array(
						'name'=>zip_entry_name($e),
						'size'=>zip_entry_filesize($e)
						);
					if(zip_entry_name($e)=='META-INF/container.xml') {
						// stub TODO
					}
       }
    }
		$this->_closeZip();
		
		$xml = $this->getFile('META-INF/container.xml');
		$this->container = simplexml_load_string($xml);
		if(!$this->container) {
			throw new Exception('cannot find or parse container doc');
		} else {
			if(!is_object($this->container->rootfiles->rootfile)) {
				throw new Exception('could not get rootfile element from container doc');
			}
			$atts = $this->container->rootfiles->rootfile->attributes();
			$this->opfpath = $atts['full-path'];
			$this->opfroot = pathinfo($this->opfpath, PATHINFO_DIRNAME);
			error_log('opfroot is '.$this->opfroot);
			$this->opfXML = $this->getFile($this->opfpath);
			$this->opf = parent::makeOpfDoc($this->opfXML);
			foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
          $type = $item->getAttribute("media-type");
          if($type=="application/x-dtbncx+xml") {
              $this->ncxpath = $this->getNcxPath($item->getAttribute("href"));
              $this->ncxXML = $this->getFile($this->opfroot . '/' . $this->ncxpath);
              if($this->ncxXML) {
								$ncxfound = true;
	              break;	
							}	else {
								$this->log('ncx '.$path . '/' . $this->ncxpath.' not located');
							}

          }
      }
	
			$this->ncx = parent::makeNcxDoc($this->ncxXML);

			return true;
		}
	}
	
	public function getOpfXML()
	{
		return $this->opfXML;
	}
	
	public function ingestZipData($data, $opspath=null)
	{
		
		// used when pulling an epub from a zipped archive
		// first writes it to disk, then ingests it whole
		if($opspath) {
			if(!is_dir($opspath)) {
				throw new Exception('ops path passed is not a directory!');
			}
		}
		
		$tmpfile = Util::getTempDir().'/epubimport'.time().'.epub';
		
		if(file_put_contents($tmpfile, $data)) {
			if($this->loadZip($tmpfile)) {
				if($opspath) {

					// TODO eliminate need for ZipArchive memory bloat. For now, it's a shortcut
					// idea here is to dump to a path if needing OPS, if not,
					// we should one day offer the option to store structure and/or zipdata
					// in memory or on disk


					if($puts = $this->writeFiles($opspath)) {

						parent::loadOPS($opspath);
					
					} else {
						
						$this->log("no files were written.");
						
					}








/*
					$this->_za = new ZipArchive();
					
					// TODO we dont have to store OPS in disk, could use memory
					
					if ($this->_za->open($tmpfile) === TRUE) {
						echo "opened\n";
						if($this->_za->extractTo($opspath)) {
							echo "extracted\n";
							$this->_za->close();
							echo "closed\n";
							if(!file_exists($opspath.'/META-INF/container.xml')) {
								echo "throwing up\n";
								throw new Exception('no container found in package!');
							} else {
								echo file_get_contents($opspath.'/META-INF/container.xml');
							}
							echo "calling parent\n";
							parent::loadOPS($opspath);
							echo "loaded OPS\n";
						} else {
							echo "could not extract this bitch\n";
						}
					} else {
						echo "throwing up again\n";
						throw new Exception('could not open zip archive!');
					}
	*/				
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
					
				}
				
				// TODO we dont have to get rid of the zip data
				$data = null;
				
				// TODO we dont have to get rid of the stored zip data either
				unlink($tmpfile);
			}
		} else {
			throw new Exception('EPUB file could not be stored locally for import');
		}
		
	}

	public function writeFiles($path)
	{
		$this->_openZip();
		$puts = 0;
		while($e = zip_read($this->ziphandle)) {
				if(zip_entry_open($this->ziphandle, $e)) {
					//$this->log('opened entry');
					$size = zip_entry_filesize($e);
					while($data = zip_entry_read($e,$size)) {
						
						$fullpath = $path .'/'. zip_entry_name($e);
						
						$dirpath = $path .'/'. pathinfo(zip_entry_name($e), PATHINFO_DIRNAME);
						
						mkdir($dirpath .'/', 03775, true);
						
						//chmod($dirpath . '/', 03775);
						//chown($dirpath, $this->apache_user);
						//chgrp($dirpath, get_current_user());
						
						if(file_put_contents($fullpath, $data)) {
							//echo $path .'/'. zip_entry_name($e)." written\n";
							$puts++;
						} else {
							$this->log($path .'/'. zip_entry_name($e) . "failed to write as user ".getmyuid().':'.get_current_user());
						}
					}
				}				
    }
		return $puts;
	}
	
	public function getFile($name)
	{
		$this->_openZip();
		$contents = '';

		while($e = zip_read($this->ziphandle)) {
        if(zip_entry_name($e)==$name) {
					if(zip_entry_open($this->ziphandle, $e)) {
						$size = zip_entry_filesize($e);
						while($data = zip_entry_read($e,$size)) {
							$contents .= $data;
						}
					}
				}
     }

		return $contents;
		
	}

	public function log($msg, $level=0)
	{
		
	
		if($this->logecho) {
			echo $msg."\n";
		} else {
			error_log($msg);
		}
		
	}
	
	private function _closeZip()
	{
		zip_close($this->ziphandle);
	}
	
	private function _openZip()
	{
		$this->ziphandle = zip_open($this->zipfile);
		if(!$this->ziphandle) {
			$this->log('could not open zipfile');
		}
	}
	
	public function enableLogging()
	{
		$this->logecho = true;
		$this->log('logging enabled');
	}
	
	public function __destruct()
	{
		$this->_closeZip();
	}
	
	
}


?>