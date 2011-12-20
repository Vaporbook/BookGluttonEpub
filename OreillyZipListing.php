<?php

/*

Originally used for zip bundles of Oreilly titles

*/

class OreillyZipListing {
	
	public function __construct($z)
	{
		// construct with full path to zip file
		$this->z = $z;
		$this->files = array();
		$this->handles = array();
  	$this->log("reading zip file ".$z);
    $this->_open();
    if(!is_resource($this->zh)) {
       $this->log('error '.$this->zh);
       exit;
    } else {
       while($e = zip_read($this->zh)) {
          $this->files[] = array(
						'name'=>zip_entry_name($e),
						'size'=>zip_entry_filesize($e)
						);
					$this->handles[zip_entry_name($e)] = $e;
       }
    }
		$this->_close();
	}

	public function getFiles()
	{
		return $this->files;
	}
	
	public function findFile($name, $linkonly=false)
	{
		$this->log('seeking '.$name);
		$data = null;
		foreach($this->files as $file) {
			$basename = pathinfo($file['name'], PATHINFO_BASENAME);
			$this->log($basename);
			if($basename==$name) {
				if($linkonly) {
					$data = 'zip://' . $this->z . '#'.$file['name'];					
				} else {
					$data = $this->getFile($file['name']);
				}
				break;
			} else {
				//echo "$basename==$name\n";
			}
		}
		return $data;
	}
	
	
	public function getFile($name)
	{
		$this->_open();
		$contents = '';

		while($e = zip_read($this->zh)) {
        if(zip_entry_name($e)==$name) {
					if(zip_entry_open($this->zh, $e)) {
						$size = zip_entry_filesize($e);
						while($data = zip_entry_read($e,$size)) {
							$contents .= $data;
						}
					}
				}
     }
		
		
		/*
		$e = $this->handles[$name];
		
		if(zip_entry_open($this->zh, $e)) {
			$size = zip_entry_filesize($e);
			while($data = zip_entry_read($e,$size)) {
				$contents .= $data;
			}
		} else {
			$this->log('could not open');
		}
		*/
		$this->_close();
		return $contents;
	}
	
	public function log($msg, $level=0)
	{
		
		//echo $msg . "\n";
		
	}
	private function _close()
	{
		zip_close($this->zh);
	}
	private function _open()
	{
		$this->zh = zip_open($this->z);
	}
	
	
	
	
	
}