<?php

//error_reporting(E_ALL | E_STRICT);
define('JAVA_LOC', '/usr/bin/java');
define('TIDY_LOC', '/usr/bin/tidy');
define('EPUBCHECK', '/usr/local/epubcheck-1.1/epubcheck-1.1.jar');
define('UPLOAD_DIR', '/tmp');
define('ZIP_LOC','/usr/bin/zip');

class BookGluttonEpub
{
  public function BookGluttonEpub()
  {
    $this->logverbose = true;
    $this->loglevel = 0;
    $this->m = null;
    $this->dcdata = array(); 
    $this->ncxXP = null;
    $this->opfXP = null;
    $this->prettyPrint = true;
    $this->readonly = false;
    $this->maxblocks = 3000;
    $this->tidyloc = TIDY_LOC;
    $this->java = JAVA_LOC;
    $this->epubcheck = $this->java . ' -jar '.EPUBCHECK;
    $this->epubcheck_ckstring = 'Epubcheck Version 1.0.3 No errors or warnings';
    $this->opf = null;
    $this->opfNS = "http://www.idpf.org/2007/opf"; // NO trailing slash
    $this->dcNS = "http://purl.org/dc/elements/1.1/"; // NEEDS trailing slash to validate
    $this->doctypeNISO = "-//NISO//DTD ncx 2005-1//EN";
    $this->ncxNS = "http://www.daisy.org/z3986/2005/ncx/";
    $this->opsmime = "application/oebps-package+xml";
    $this->daisydtd = "http://www.daisy.org/z3986/2005/ncx-2005-1.dtd";
    $this->ncxmime = "application/x-dtbncx+xml";          
    $this->packageVersion = "2.0";
    $this->xmllang = "en-US";
    $this->title = '';
    $this->author = '';
    $this->zipQ = array();
    $this->_za = null;
    $this->ocf_filename = null; // temporary epub filename
    $this->useNavDivs = false;
    $this->useNavDocs = true;
    $this->includecover = false;  
    $this->workpath = DiskUtil::getTempDir(); // this will be the path to write the epub to
    $this->opsname = uniqid(); // unique name for ops container directory
    $this->packagepath = $this->workpath . '/' .$this->opsname; // this will be the working package dir (ops)
    $this->ncxpath = "index.ncx";
    $this->opfpath = "index.opf";
    $this->mimetypepath = $this->packagepath . '/mimetype'; // filename of mimetype file
    $this->metapath = $this->packagepath . '/META-INF';
    $this->opspath = $this->packagepath;
    $this->navmaplabel = 'Table of Contents';
    $this->uniqIDscheme = "PrimaryID";
    $this->uniqIDval = 'not set';
    $this->opsrel = ''; // this is the rel path within the package structure where content is found.

    $this->ziphandle_limit = 100; // system-dependent
    $this->suppress_purify = true;
    $this->conversionIndexParsed = false;
    $this->conversionMetasParsed = false;
    $this->hasPrimaryIdSet = false;
    $this->ncxCurPlayOrder = 0;
    $this->ncxGeneratedDepth = 0;
    $this->ncxGeneratedLength = 0;
    $this->ncxGeneratedNavMapCurPoint = null;
    $this->ncxLastDepth = 0;
    $this->preflight = array();
    $this->tmpdump = null;
    $this->ncxSpineadd = array();
    $this->zipstem = '';
    $this->unsavedchanges = false;
    $this->deferredSpine = false;
    $this->xhtml11doctype = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">';
    $this->htmltag = '<html xmlns="http://www.w3.org/1999/xhtml">';
      // A default XHTML 1.1 template for importing new content
    $this->doctmpl =<<<END
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head></head>
</html>
END
;

    $this->logerr('BookGluttonEpub instantiated', 1);
        
  }
  public function setReadonly($bool)
  {
     $this->readonly = $bool;
  }
  
  public function create($meta=array('title'=>null,
                                      'author'=>null,
                                      'language'=>null,
                                      'desc'=>null,
                                      'rights'=>null))
  {
        
  }
  
   
  public function createFromAssets($root=null, $files)
  {
    /**
    Expects a path to an empty directory in which to create the OPS,
    and an array of assets, each of which is a hash of path, content
    keyvalues. content should be base64 encoded and will be decoded and
    written to path relative to root.
    */

    if(!$root) {
      $root = $this->packagepath; // defaults to uniqid in temp dir
    }
    if(!file_exists($root)) {
      DiskUtil::makeDir($root);
    }

    $this->ocf_filename = $this->_makeEpubTargetFromAssets($root, $files);
    
    /*
    foreach($files as $file) {
      $file['content'] = base64_decode($file['content']);
      $this->writeFile($file);
    }
    */

    //$this->loadOPS($root);
    $this->open($this->ocf_filename);
    $this->_saveMeta();
  }


  private function _makeEpubTargetFromAssets($packagedir, $files)
  {
    
    // expects base64 encoded content!!!!
    
    // see the other function using ZipArchive for
    // notes on why we do it this way

    $this->mimetypepath = $packagedir . '/mimetype';

    $this->_writeFile($this->mimetypepath, $this->getMimetypeString());
  
    if(file_exists($this->mimetypepath)) {
      $this->logerr('success!');
    } else {
      throw new Exception('could not create a mimetype file for this ops structure');
    }
    
    $arcname = $packagedir.'.epub';
    //error_log('created archive file:'.$arcname);
    $zipcmd = ZIP_LOC; // path to zip command
    $zipflags = '-0 -j -X';
    $zipcmdfull = "$zipcmd $zipflags $arcname $this->mimetypepath";

    exec(escapeshellcmd($zipcmdfull), $output);
    $this->logerr('output was:'.print_r($output, true));

    $zip = new ZipArchive();

    if($zip->open($arcname)!==TRUE) {
       $this->logerr("cannot open <$arcname>");     
    }
    $zipq = array();
    // asm: the following line causes problems reading these on stanza, so leave commented
    $zip->addEmptyDir('META-INF');
        
    $dirnames = array();
    foreach($files as $file) {
      $pi = pathinfo($file['path']);
      if($pi['dirname']!="." && $pi['dirname'] != "..") {
        $dirnames[$pi['dirname']] = $pi['dirname'];
      }
    }

    $fullpath = "";
    // make sure dirs exist
    foreach($dirnames as $dirpath=>$bool) {
      $dirs = explode('/', $dirpath);
      $fullpath = "";
      foreach($dirs as $step) {
        if($fullpath=="") {
          $fullpath = $step;
        } else {
          $fullpath = $fullpath . '/'.$step;          
        }
        $zip->addEmptyDir($fullpath);         
      }
    }
    $filenum = 1;
    foreach($files as $file) {
      if($file['path']=='mimetype') continue;
      $filenum++;
      // SEE NOTE ABOUT FILE HANDLE LIMITS
      if($filenum > $this->ziphandle_limit) {
        $zip->close();
        $zip->open($arcname);
        $filenum = 1;
      }
      $zip->addFromString($file['path'], base64_decode($file['content']));
    }
    $zip->close();
    $arctmp = UPLOAD_DIR.'/'.uniqid().'.epub';
    copy($arcname, $arctmp);
    $zipflags = "-F $arctmp";
    $zipcmdfull = "$zipcmd $zipflags";    
    if(!exec(escapeshellcmd($zipcmdfull), $output)) {
      throw new Exception('could not fix zip file');
    }
    //error_log('zip -F output:'.print_r($output, true));
    unlink($arcname);
    DiskUtil::xRename($arctmp, $arcname);
    return $arcname;
    
  }


  public function open($epub)
  {
 
    $this->_za = new ZipArchive();
    if ($this->_za->open($epub) === TRUE) {
      $this->numFiles = $this->_za->numFiles;
      
      //$this->logerr('files found in archive are:');
      $this->packagepath = $this->workpath . '/' .$this->opsname; // this will be the working package dir (ops)
      $this->_za->extractTo($this->packagepath);
      $this->mimetypepath = $this->packagepath . '/mimetype'; // filename of mimetype file
      $this->metapath = $this->packagepath . '/META-INF';
      $this->opspath = $this->packagepath;

      $this->loadOPS($this->packagepath);
      
    } else {
      throw (new Exception('cannot open zipfile:'.$epub));
    }
  }
  //_makeEpubTmp()

  public function openRemote($href)
  {
    $tmpfile = DiskUtil::getTempDir().'/epubimport'.time().'.epub';
    if(file_put_contents($tmpfile, file_get_contents($href))) {
      $this->open($tmpfile);
      return $tmpfile;
    } else {
      throw new Exception('EPUB file could not be stored locally for import');
    }
  }
  
  public function ingestRaw($data)
  {
    $tmpfile = DiskUtil::getTempDir().'/epubimport'.time().'.epub';
    
    if(file_put_contents($tmpfile, $data)) {
      $this->open($tmpfile);
      // return tmpfile location so it can be cleaned up
      return $tmpfile;
    } else {
      throw new Exception('EPUB file could not be stored locally for import');
    }
  }
  
   public function isWritable()
   {

      $path = $this->packagepath;
    $mimetype = $path . '/mimetype';
      $opf = $path . '/'. $this->opfpath;
      $ncx = $path . '/'. $this->ncxpath;
      $metafile = $this->metapath.'/container.xml';
      @$res = is_writable($path) &&
               is_writable($mimetype) &&
                  is_writable($opf) &&
                     is_writable($ncx) &&
                        is_writable($metafile);
      return $res;
   }
   
   public function opfWritable()
   {
      return is_writable($this->packagepath . '/'. $this->opfpath);
   }
   
   public function ncxWritable()
   {
      return is_writable($this->packagepath . '/'. $this->opfpath);      
   }
   
   public function mimeWritable()
   {
      return is_writable($this->packagepath . '/mimetype');    
   }   
   
   public function pathWritable()
   {
      return is_writable($this->packagepath);    
   }      
   
   public function metapathWritable()
   {
      return is_writable($this->metapath);    
   }     
   
   public function enableWrite()
   {
      $path = $this->packagepath;
    $mimetype = $path . '/mimetype';
      $opf = $path . '/'. $this->opfpath;
      $ncx = $path . '/'. $this->ncxpath;
      $metafile = $this->metapath.'/container.xml';
      $changes = array($path, $mimetype, $opf, $ncx, $metafile);
      $success = 0;
      foreach($changes as $f) {
      
         if(chmod($f, 0777)) {
            $success++;
         }
         
      }
      return ($success===count($changes));
   }
   
   public function disableWrite($mode = 0644)
   {
      $path = $this->packagepath;
    $mimetype = $path . '/mimetype';
      $opf = $path . '/'. $this->opfpath;
      $ncx = $path . '/'. $this->ncxpath;
      $metafile = $this->metapath.'/container.xml';
      $changes = array($path, $mimetype, $opf, $ncx, $metafile);
      $success = 0;
      foreach($changes as $f) {
         if(is_dir($f)) {
            if(chmod($f, 0755)) {
               $success++;
            }     
         } else {
            if(chmod($f, $mode)) {
               $success++;
            }
         }
      }
      return ($success===count($changes));     
   }
   
   public function upgradeMetadata()
   {
      $pack = $this->getPackageEl();
      if($pack->getElementsByTagName('dc-metadata')->length > 0) {
      $dcmetadata = $pack->getElementsByTagName('dc-metadata')->item(0);
      if(!($metadata = $pack->getElementsByTagName('metadata')->item(0))) {
         $metadata = $pack->appendChild($this->getOpfDoc()->createElement('metadata'));                  
      }
      $children = $dcmetadata->childNodes;
      for($i = 0; $i < $children->length; $i++) {
         $meta = $children->item($i);
         $metadata->appendChild($meta->cloneNode(true));
      }
      $metadata->removeChild($dcmetadata);
      }
   }
   
   public function regeneratePrimaryId()
   {
                          
       $uuid = uuidGen::generateUuid();
       $content = 'urn:uuid:'.$uuid;
       $this->replacePrimaryIdValue($content);
       return $content;

   }
   
   
   public function getPackageEl()
   {
      return $this->getOpfDoc()->getElementsByTagName('package')->item(0);
   }

   
  public function loadOPS($path)
  {
    // loads a local OPS file structure into xml containers

    if(!$this->_za && class_exists('ZipArchive')) {
      $this->_za = new ZipArchive();
    } else {
      $this->_za = null;
    }
    $this->packagepath = $path; // this will be the working package dir (ops)   
    $this->opspath = $this->packagepath; // alias
    $this->mimetypepath = $this->packagepath . '/mimetype'; // filename of mimetype file
    $this->metapath = $this->packagepath . '/META-INF';

    // CONTENT.XML
    
    $this->contdoc = $this->_makeContDoc($this->getContainerXMLRaw());
    $this->rootfile = $this->_getRootFileName();
    // get the relative path to it, trimming dots and slashes
    $this->opsrel = trim(pathinfo($this->rootfile, PATHINFO_DIRNAME),'/.');
    
   
    // opffile, oppath and rootfile are all the same!!!
    
    $this->opfpath = $this->rootfile; // the relative path to the opf file    
    $this->opffile = $this->opfpath;


    // .OPF
    if(!$this->opfpath || !file_exists($path . '/'. $this->opfpath)) {
      throw new Exception('not a valid path for opf file');
    }
      
    $opf = file_get_contents($path . '/'. $this->opfpath);
    if(!$opf) {
       throw new Exception("something is wrong there is no opf file at $path/".$this->opfpath);
    }
    $this->opf = $this->_makeOpfDoc($opf);
     
    
    $this->ncx = $this->_makeNcxDoc($this->getNcxXMLRaw());   
    
    // add manifest files to zipQ
    
    //error_log('adding these to a zip listing');
    
    
    $this->_addToZipQ();
    
    // .NCX
    
    
    // do a lil check on things
    
    $this->opf_metadataNode->setAttribute('xmlns:dc', "http://purl.org/dc/elements/1.1/");
    $this->opf_metadataNode->setAttribute('xmlns:opf',"http://www.idpf.org/2007/opf");
    // check for lang   
    
    if(!$this->hasLang()) {
      $this->addMeta('language', $this->xmllang);
    }
    
    
    // numerical ids not allowed
    
    foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
      $id =  $item->getAttribute('id');
      if(preg_match('/^\d/',$id)) {
        $item->setAttribute('id', 'id'.$id);
      }
    }
    
    foreach($this->opf_spineNode->getElementsByTagName('itemref') as $itemref) {
      $id = $itemref->getAttribute('idref');
      if(preg_match('/^\d/',$id)) {
        $itemref->setAttribute('idref', 'id'.$id);
      }
    }
    

  }

  public function fileExists($href)
  {
    return file_exists($this->getAbs($href));
  }
  
  public function getAbs($href)
  {
    /**
    
    Get full disk path to href of opf item
    
    */
    $rel = (strlen($this->opsrel)>0) ? $this->opsrel . '/' : '';
    return $this->opspath . '/' . $rel . $href;
  }
  
  private function _addToZipQ()
  {
    
    /**

    Add all manifest files to zip archive object.
    Called during LoadOPS as part of the init
    for a package. If a file is not found,
    throws Exception.
    
    */
    
    if($this->opf_manifestNode) {
        foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
      $href = $item->getAttribute('href');

            if(!$this->fileExists($href)) {
              $dump = $this->opf->saveXML();
              //error_log('epub:file at '.$href.' does not exist'."\n$dump");
            }
    
            $abs = $this->getAbs($href);
            $rel = $this->getRel($href);
            if($href && file_exists($abs)) { // only add if it's a valid key and the file exists
              
              // array_key_exists is failing in this, dont know why
              // not even sure why we needed it here
                
             // if(array_key_exists($rel, $this->zipQ)===FALSE) { // preserve existing (primacy rule)
                
                $this->zipQ[$rel]=$abs;
              
              //} else { // this is a duplicate id and should be removed

             //   if($this->opf_manifestNode->removeChild($item)) {
             //         error_log('epub:removed item with duplicate id:'.$rel);
              //  }

             }
           
        }
  } else {
      throw new Exception('manifest is not defined yet');
    }
  }
  
  private function _getRootFileName()
  {
    return $this->contdoc->getElementsByTagName('rootfiles')->item(0)->getElementsByTagName('rootfile')->item(0)->getAttribute('full-path');
  }
  
  public function getContainerXMLRaw()
  {
    if(@!($container = file_get_contents($this->metapath.'/container.xml'))) {
      $msg = "";
      if(is_dir($this->metapath)) { $msg = ' and it is not even an existent directory!!'; }
      //$result = DiskUtil::findFile($path, '*.opf'); // like find $path -name $regex
      $result = false;
      //TODO
      if($result) {
        $this->opffile = $result;
        $this->opfpath = $this->opffile;
        $container = null;
      } else {
        throw new Exception('container file not found in '.$this->metapath.$msg.' plus could not create');        
      } 
    } else {
      return $container;
    }
    
  }
  
  public function getNcxXMLRaw()
  {
    if(!$this->opf_spineNode||!$this->opfXP) throw new Exception('must define an xpath parser and a spine node representation before calling this');
    $tocatt = $this->opf_spineNode->getAttribute('toc');
    $path = $this->packagepath;
  //  error_log('//*[@id="'.$tocatt.'"]');
    $tocitem = $this->opfXP->evaluate('//*[@id="'.$tocatt.'"]');
    if($tocitem->length > 0) {
          //error_log('found an item in manifest matching id '.$tocatt.' specified by spines toc attribute');
          if($tocitem->item(0)->getAttribute('media-type')=='text/xml') {
              // fix ncx for stanza - eliminate incorrect media-type value
             $tocitem->item(0)->setAttribute('media-type', 'application/x-dtbncx+xml');
          }
          if($tocitem->item(0)->getAttribute('href')) {       
             $this->ncxpath = $this->getNcxPath($tocitem->item(0)->getAttribute('href'));            
          } else {
            // error_log('found an item for the ncx but the href is not set. going to try to find the right file to attribute to this...');
             $ncxfound = $this->_seekNcxFile($tocitem->item(0));
          }
    } else {
        
           // an item with the specified id for the ncx item is not found:
      
            // this should be fatal, but some software like Calibre does
            // not put a toc attribute on the spine, even though there's
            // an ncx in the manifest. no point in failing here without
            // double-checking the manifest, even though against the spec
               
            $ncxfound = false;
            error_log('no ncx designation in spine attribute, seeking...');
            foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
                $type = $item->getAttribute("media-type");
                if($type=="application/x-dtbncx+xml") {
                    $this->ncxpath = $this->getNcxPath($item->getAttribute("href"));
                    if(!file_exists($path . '/' . $this->ncxpath)) {
                        error_log('ncx file not found at '.$path . '/' . $this->ncxpath);
                    }
                    $ncxfound = true;
                    break;
                }
            }         
            if(!$ncxfound) { // if still not found, seek
                $ncxfound = $this->_seekNcxFile();
                if(!$ncxfound) { // still no? fucked
                  throw new Exception('ncx not found, even after searching recursively');
                }
                
            }
    }
    if(!$this->ncxpath) {
      if($this->_seekNcxFile()) {
        //error_log('found ncx by searching filesystem');
      } else {
        //error_log('cannot locate ncx');
      }
    }
    
    //error_log('checking for file at '.$path . '/'. $this->ncxpath);
    if(!file_exists($path . '/'. $this->ncxpath) || !$this->ncxpath) { // make sure file there
       //error_log('throwing Exception: ncx file not found');
       throw new Exception('ncx file not found at '.$this->ncxpath);
    }
    //error_log('ncxpath set to '.$this->ncxpath);
    return file_get_contents($path .'/'. $this->ncxpath);   
  }
  
  private function _seekNcxFile($tocitem=null)
  {
       $tocatt = $this->opf_spineNode->getAttribute('toc');
       if(!$tocatt) $tocatt = 'toc';
       $ncxfound = false;
       // be smart, just search the ops for files matching /\.ncx$/i
        $files = array();
        exec("find ".$this->packagepath." -type f -name '*'", $files);
        
        //error_log(print_r($files, true));
        foreach($files as $candidate) {
          if(preg_match('/\.ncx$/i', trim($candidate))) {
            
              // add the item to the manifest, with id=$tocatt
            
              if(!$tocitem) { // if the item is not already defined
               // error_log('creating a new manifest item for the ncx');
                $item = $this->opf_manifestNode->appendChild($this->opf->createElement('item'));
                $item->setAttribute('id', $tocatt);
              } else {
                $item = $tocitem;
              }
              $newhref = trim(str_replace($this->packagepath, '', $candidate), './ ');
             // error_log('**find command found ncx href: '.$newhref);
              $item->setAttribute('href', $newhref);
              $item->setAttribute('media-type', 'application/x-dtbncx+xml');
              $this->ncxpath = $this->getNcxPath($newhref);
              //$this->_saveMeta();
              
              $ncxfound = true;
          }
        }
        return $ncxfound;

  }


  public function getNcxPath($href)
  {
    // conditionally appends a relative path to the ncx manifest href
    // passed in, based on whether opsrel is rootlever or not
    
    //error_log('opsrel is '.$this->opsrel);
    
    return (strlen($this->opsrel)>0) ? $this->opsrel . '/' . $href : $href;
  }
  
   
   /* Conversion methods */
   
   

   public function includeCover($cover)
  {
    //$this->logerr('setting cover HTML:'.$cover);
    $this->includecover = $cover;
  }
  
  public function setPretty($bool)
  {
    //$this->logerr('setPretty called');
    $this->prettyPrint = $bool;
  }

  public function loadSource($filename)
  {

    
    // detect zip or epub and forwards to either
    // open method or loadSourceFromZip
    
    if(!$filename) throw new Exception ('loadSource requires a non-null and non-empty filename');
    $this->logerr('loadSource:'.$filename, 4);
    
    //asm: this was causing the title to be 'Untitled'
    /*
    if(!@$this->opf) { // if create has not been called yet
      //error_log('create has not been called, calling it now');
      $this->create(array('title'=>$this->getTitle(), 'author'=>$this->getAuthor())); 
    }
    */
    
  
    if(!@$this->opf) {
         $this->_makeDirs();
         $this->opf = $this->_makeOpfDoc();
         $this->ncx = $this->_makeNcxDoc();
         $this->contdoc = $this->_makeContDoc();
    }
    
    $base_href = '';
    $docroot = '';
    if(preg_match('/^(http:\/\/.+?)([^\/]*?)$/', $filename, $urlmatches)) { // pre-fetch remote files
      // store url for later processing - add trailing slash if needed
      $hostpath = $urlmatches[1];
      $urlinfo = parse_url($filename);

      $docroot = ($urlinfo['host']) ? 'http://' . $urlinfo['host'] : '';
      $base_href = (preg_match('/\/$/', $hostpath)) ? $hostpath : $hostpath . '/';
      
      //$this->logerr('trying to cache url:'.$filename);
      $tmpwork = DiskUtil::getTempDir() . '/' . uniqid();
      if(!($remote = file_get_contents($filename))) {
        throw new Exception('cannot cache remote url');       
      }
      if(!file_put_contents($tmpwork, $remote)) {
        throw new Exception('cannot cache remote url');
      } else {
        $filename = $tmpwork;
      }
    }
    $snip = file_get_contents($filename,null,null,0,2);
    if(!$snip) { throw new Exception('file '.$filename.' does not exist'); }
    if ($snip=='PK') { // .zip or epub
      
      $this->loadSourceFromZip($filename);
      $this->_saveMeta();
      $this->loadOPS($this->packagepath);
      
    } else {
         throw new Exception("This method only accepts zipped HTML (web) archives conforming to the UBO spec");
/*

      $pi = pathinfo($filename);
      $basename = $pi['basename'];
      $id = $this->_validID('source');
      $doc = $this->_domFromDoc($filename);
      $xp = new DOMXPath($doc);
      $xp->registerNamespace("ht", "http://www.w3.org/1999/xhtml");




      // build package elements
      $this->_setIdentifier();
      $this->setPublisher('BookGlutton API (www.BookGlutton.com)');
      $this->_guessMetas($doc, $xp);

      // add a cover
    
      if($this->includecover!=false) {
        //$this->logerr('adding cover item:'.print_r($this->includecover, true));
        $this->_addCoverItem();
      }
    
      //$oochaps = $xp->query('//ht:p[@class="ChapterTitle"]', $bodynode);
      $heads = $xp->query('//ht:h1|//ht:h2|//ht:h3', $doc);
      $images = $doc->getElementsByTagname('img');
      $this->logerr('found '.$heads->length.' headings here');
      // save the document back out and add the items from the headings
      if($this->useNavDivs==true) {
        $doc = $this->_headsToNavDivs($doc, $heads, $basename);
      } else {
        if($this->useNavDocs==true) {
          $this->logerr('calling headsToNavDocs');
          try {
            $this->_headsToNavDocs($doc, $heads, $basename);
          } catch (Exception $e) {
            error_log('ignoring caught Exception in _headsToNavDocs:'.$e->getMessage());
          }
          return; // skip adding the original source
        } else {
          $this->_headsToNavItems($doc, $heads, $basename);
        }
      }
  
      foreach($images as $image) { // images must not be in spine!
        $src = $image->getAttribute('src');
        if(!preg_match('/^http:\/\//i', $src)) { // if img is not remote
          $mime = $this->_getMimeFromExt($src);
          $this->addItem($this->_validID('image'), $src, $mime, null, null);          
        }
      }

      $this->logerr('loadSource:adding source item now', 2);
      $this->addItem($id, $basename, 'application/xhtml+xml', $doc->saveXML(), 'yes');
    */
      }
  }

  public function getPackagePath()
  {
    return $this->packagepath;
  }

  public function setPackagePath($p)
  {
    $this->packagepath = $p;
    $this->metapath = $this->packagepath . '/META-INF';
    $this->opspath = $this->packagepath;
  }

  public function loadSourceFromZip($zipfile)
  {
      //error_log('loading:'.$zipfile);
    $za = new ZipArchive();
    if ($za->open($zipfile) === TRUE) { // valid zip
      
      // check if its epub
      $checkit = $za->statIndex(0);
      if($checkit['name']=='mimetype') {
        if($fp = $za->getStream('mimetype')) { // is a valid file
          $contents = '';
          while (!feof($fp)) { // suck contents in
                        $contents .= fread($fp, 2);
                    }
          fclose($fp);
          $this->logerr($contents, 3);
          if(preg_match('/application\/epub\+zip/',$contents)) {
            $this->logerr('this is an epub file!', 1);
            $za->close();
            $this->open($zipfile); // open as epub
            return;
          }
        }
      } else {
        $this->logerr('No mimetype file found in archive, probably not epub', 2);
      }
      $numFiles = $za->numFiles;
        // error_log($numFiles . " found in archive...");
      $acceptlist = array();
         $firstdoc = null;
      $order=0;
      for ($i=0; $i<$numFiles;$i++) {
                $stats = ($za->statIndex($i));
        // do some filtering by file extension first, only acceptable types
        if(preg_match('/(xml|x?html?|gif|jpe?g|svg|png|swf|css)$/i',$stats['name'])) {
          if(preg_match('/^[^\._]/', $stats['name'])) { // won't process hidden or system files
            $mime = $this->_getMimeFromExt($stats['name']); // all html and xml types return '...+xml' for this
            $isimg = preg_match('/(jpe?g|gif|png|swf|css|svg)$/i', $mime);
            $prefix = ($isimg) ? 'image' : 'item';
            $itemid = $this->_validID($prefix);
            $addtospine = ($isimg) ? null : 'yes';
            $fp = $za->getStream($stats['name']);
            if($fp) { // is a valid file
                      $contents = '';
                      while (!feof($fp)) { $contents .= fread($fp, 2);}
                      fclose($fp);
              // XML and HTML files - content docs
              if (preg_match('/xml$/i', $mime)) {

                        $this->preflightReport('INFO: '.$mime.'  "'.$stats['name'].'" ('.strlen($contents).' bytes)');
                        $this->_loadXMLContent($itemid, $stats['name'], $mime, $contents);

              } else { // not xml or html, add to manifest        
                $this->logerr('Not a content document type, adding to manifest only.',2);
                        $this->preflightReport('INFO: '.$mime.'  "'.$stats['name'].'" ('.strlen($contents).' bytes)');
                $this->addItem($itemid, $stats['name'], $mime, $contents, false);
                $contents = null; // free mem
              }
            } else {
                            
                            //error_log("INFO: invalid file pointer from zip/EPUB");
                  } // fail silently if not a valid file
          } else {
                            
                        //error_log("Epub: not an allowed file");
                        $this->preflightReport("WARN: ".$stats['name']." skipped, not an allowed file");
                        
               }
        } else {
              // error_log("Epub: not an allowed file extension");
            }
      }

         if($this->includecover!=false) {
             $this->logerr('Adding a cover',2);
             $this->_addCoverItem();
         }
         if(!$this->hasPrimaryIdSet) {
            
            $this->_setIdentifier();
      }
         //$this->setPublisher('BookGlutton API (www.BookGlutton.com)');
         if($this->deferredSpine===true) {
            //error_log(print_r($this->ncxSpineadd),true);
            
            foreach($this->ncxSpineadd as $key=>$path) {

               foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
                  //error_log($path.'=='.$item->getAttribute('href'));
                  
                  if($item->getAttribute('href')==$path) {
                     //error_log('adding to spine');                     
                     $this->addSpineRef($item->getAttribute('id'), 'yes');
                     $this->preflightReport("INFO: added $path to spine");
                     break;
                  }
               }

               
            }
         
         } else {
             throw new Exception('no UBO index file found to build EPUB structure');
         }
         
    } else { // complain if this isnt even a valid zip
      throw (new Exception('cannot open zipfile:'.$zipfile));
    }

  }
  
   private function _loadXMLContent ($itemid, $name, $mime, $contents)
   {
      $this->logerr('This is to be an xml based content doc, judging from the extension:'.$name,2);
      if(preg_match('/index\.html?$/i', $name)) {
         $isindex = true;
         $this->preflightReport("INFO: Found UBO index file");
      } else {
         $isindex = false;
      }
      $tmp = $this->opspath . '/' . $this->opsrel . '/' . $name; // path to new file (return from addItem?)
      DiskUtil::assertPath(pathinfo($tmp, PATHINFO_DIRNAME));
      $doc = new DomDocument();
      
      if(preg_match('/<!DOCTYPE[^>]+?xhtml 1.1[^>]+?>/im', $contents)) {
         $contents = preg_replace('/<!DOCTYPE[^>]+?>/m', $this->xhtml11doctype, $contents);
      }
      
      $contents = preg_replace('/<html[^>]+?>/m', $this->htmltag, $contents);
      
      if(@!$doc->loadXML($contents)) {
         
         $this->preflightReport("ERROR: Could not parse the file ".$name.". It may interfere with validation.",1);
         
      }
      
      $contents = null; // free mem
      if($doc->getElementsByTagName('title')->length > 0) {
         $title = $doc->getElementsByTagName('title')->item(0)->textContent;
      }
      if($isindex) {
      // set metas from index file
         $this->_parseMetasFromDoc($doc);
      // build navigation doc
         $this->ncxCurPlayOrder = 0;
         $this->ncxGeneratedDepth = 0;
         $this->ncxGeneratedLength = 0;
         $this->ncxGeneratedNavMapCurPoint = null;
         $this->ncxLastDepth = 0;
         $this->ncxSpineadd = array();
         $this->zipstem = pathinfo($name, PATHINFO_DIRNAME);
         $this->_recurseList($doc->getElementsByTagName('ol')->item(0));
      // defer building spine until after all docs accounted for
         $this->deferredSpine = true;
      }
      $this->addItem($itemid, $name, $mime, $doc->saveXML());
      $this->preflightReport("INFO: file ".$name." added");

      $doc = null; // free mem
      //$this->addNavItem($itemid, $title, $name, 'document');
   }
   
   
   private function _recurseList($olel, $depth=0)
   {
      if(!$olel) {
         $this->preflightReport("ERROR: Could not find the toc list element. Be sure to use an ORDERED LIST element in your index.html file, with class attribute set to 'toc.' This will cause invalid results.",1);
         return;
      }

      if($children = $olel->childNodes) {
         foreach($children as $ol) {
            if($child = $ol->firstChild) {
               $this->_processHtml($child, $depth);
            } else if($child = $ol->nextSibling) {
               $this->_processHtml($child, $depth);
            }
         }
      }
   }
   
   
   private function _processHtml($child,$depth)
   {
      if($child->nodeType==1) {
         if($child->nodeName=='li') {
            //error_log('li found');
            $this->preflightReport("INFO: toc list item found - ".$child->nodeValue);
            foreach($child->childNodes as $ch) {
               if($ch->nodeName=='a') {
                  $title = $ch->textContent;
                  $prefix = ($this->zipstem !='.') ? $this->zipstem .'/' :'';
                  $name =  $prefix . $ch->getAttribute('href');
                  if($depth>0) { // not at top level
                     if($depth>$this->ncxLastDepth) { // we're deeper in now
                        $nps = $this->ncx_navMapNode->getElementsByTagName('navPoint');
                        $np = $nps->item($nps->length-1); // find last element instead of navmap
                        $this->ncxGeneratedNavMapCurPoint = $np;
                     }
                  } else if($depth==0) { // at navmap level
                     $this->ncxGeneratedNavMapCurPoint = null;
                  }
                  $this->addNavItem('navPoint'.$this->ncxGeneratedLength, $title, $name, $child->getAttribute('class'),$this->ncxGeneratedNavMapCurPoint);
                  
                  $this->preflightReport("NCX: ".$title." added with target of ".$name);
                  
                  $pre = (strlen($this->opsrel)>0) ? $this->opsrel . '/' : '';
                  $find = $pre.$name;
                  $prts = explode('#',$find);
                  $find = $prts[0];
                  
                  /* fix for path bug */
                  $fparts = preg_replace('/^\.\//','',$find); // strip leading path
                  
                  $this->ncxSpineadd[$fparts]=$fparts;
                  
                  /* end fix */
                  
                  $this->ncxGeneratedLength++;                  
                  break;
               }
            }
            $this->ncxLastDepth = $depth;
            $depth++;
         }
      }
      $this->_recurseList($child, $depth);
   }
   
   private function _parseMetasFromDoc($doc)
   {
      if($this->conversionMetasParsed || $this->conversionIndexParsed) return;
      $metas = $doc->getElementsByTagName('meta');
      foreach($metas as $meta) {
         $mn=strtolower($meta->getAttribute('name'));
         $c = $meta->getAttribute('content');
         $role=null;$type=null;
         if($mn=='ubo.primaryid') {
            $scheme = $meta->getAttribute('scheme');
            $this->_setIdentifier($c, $scheme, true);
            $this->preflightReport("Primary Id set from UBO metadata: ".$c);
            $this->hasPrimaryIdSet=true;
         } elseif($mn=='ubo.cover') {
            $this->addCoverMeta($c);
         } elseif($mn=='dc.identifier') {
            $scheme = $meta->getAttribute('scheme');
            $this->_setIdentifier($c, $scheme, false);
            $this->preflightReport("Added identifier from DC metadata: ".$c);
         } elseif ($mn=='dc.title') {
            $this->addMeta('title', $c);
            $this->preflightReport("Added title from DC metadata: ".$c);
         } elseif (preg_match('/^dc.creator(.*?)$/', $mn, $matches)) {
            if(count($matches)>1) {
               if(preg_match('/^\./', $matches[1])) {
                  $role = substr($matches[1],1);                  
               }
            }
            if($role!='aut') {
              $role=null;
            }
            $this->addMeta('creator', $c, $role);
            $this->preflightReport("Added creator from DC metadata: ".$c);
         } elseif ($mn=='dc.language') {
            $this->addMeta('language', $c);
            $this->preflightReport("Set language from DC metadata: ".$c);         
         } elseif ($mn=='dc.publisher') {
            $this->addMeta('publisher', $c); 
            $this->preflightReport("Set publisher from DC metadata: ".$c);                
         } elseif (preg_match('/^dc.date(.*?)$/', $mn, $matches)) {
            if(count($matches)>1) {
               $type = substr($matches[1],1);
            }
            $this->addMeta('date', $c, $type);
            $this->preflightReport("Set date from DC metadata: ".$c);               
         } elseif ($mn=='dc.description') {
            $this->addMeta('description', $c);
            $this->preflightReport("Set description from DC metadata: ".$c);
         } elseif (preg_match('/^dc.contributor(.*?)$/', $mn, $matches)) {
             if(count($matches)>1) {
               $role = substr($matches[1],1);
            }             
            $this->addMeta('contributor', $c, $role);
            $this->preflightReport("Set contributor from DC metadata: ".$c);
         }
      }
      $this->conversionMetasParsed = true;
      $this->conversionIndexParsed = true;
   }

   public function fix()
   {
     
     /* magic method that attempts to repair common problems */
     
     // missing ncx
    
     
     
     
     
     
   }

   
   
   
   /* post conversion test in lieu of epubcheck */
    
  public function testOPS($path=null)
  {
        $this->_saveMeta();
    if($path==null) $path = $this->packagepath;
    $mimetypepath = $path . '/'. $this->getMimetypeFilename(); // filename of mimetype file
    $metapath = $path . '/META-INF';
    $result = array();
    $result[0] = 'fail';
    $result[1] = 'error message';
    if(is_dir($metapath)) { // dir exists
      if($container = file_get_contents($metapath.'/container.xml')) { // found container file
        $contdoc = new DomDocument();
        if($cd = $contdoc->loadXML($container)) { // loaded container xml       
          $fp = $contdoc->getElementsByTagName('rootfiles')->item(0)->getElementsByTagName('rootfile')->item(0)->getAttribute('full-path');
          if($fullp = $this->_getRelPathWithOpf($fp)) { // found full path to opf file
            $p = (strlen($fullp[0])>0) ? "$path/$fullp[0]":"$path";
            $op = "$p/$fullp[1]";
            $this->logerr($op, 2);
            if(file_exists($op)) { // found opf file
              $opf = new DomDocument('1.0','utf-8');
              $opf->preserveWhiteSpace = FALSE;
              $opf->loadXML(file_get_contents($op));
              $package = $opf->getElementsByTagName('package')->item(0);
              $meta = $package->getElementsByTagName('metadata')->item(0);
              $mani = $package->getElementsByTagName('manifest')->item(0);
              $spine = $package->getElementsByTagName('spine')->item(0);
              if($meta && $mani && $spine) {
                $tocatt = $spine->getAttribute('toc');
                $opfXP = new DomXpath($opf);
                $opfXP->registerNamespace("opfns", $this->opfNS);
                $opfXP->registerNamespace("dc", $this->dcNS);
                $tocitem = $opfXP->evaluate('//*[@id="'.$tocatt.'"]');
                if($tocitem->length > 0) {
                  if(file_exists($p . '/'. $tocitem->item(0)->getAttribute('href'))) {
                    $result[0] = 'pass';
                    $result[1] = array('opf'=>$fp);
                    //foreach($mani->getElementsByTagName('item') as $item) {

                    //  $href = $item->getAttribute('href');
                    //}
                  } else { $result[1] = "ncx file not found:".$p . '/'. $tocitem->item(0)->getAttribute('href')."\n"; }
                } else { $result[1] = "no item with ncx id found\n"; }
              } else { $result[1] = "one of the three required opf nodes is missing\n"; } 
            } else { $result[1] = "opf not found\n"; }
          } else { $result[1] = "full path to opf not found\n"; }
        } else { $result[1] = "container file not loaded\n"; }
      } else { $result[1] = "container file not found\n"; }
    } else { $result[1] = "meta-inf dir no exist\n"; }
    return $result;
  }
  
  
   
   
   
  /* XML convenience methods */
   
   
   
   
  private function _getParentDiv($node)
  {
    $parent = $node->parentNode;
    while($parent) {
      if(strtolower($parent->nodeName) == 'div') {
        return $parent;
      }
    }
    return null;
  }
   
  
   /* 
   
   XML OPS MODEL functions
   

   Readers - return elements (usually unattached)
   Factories - create elements (also unattached)
   Builders - attach elements to dom docs
   Getters and Setters
   
   */
   
   
 
   /* Public getters and setters for OPS metadata */
   

   
   /* XML getters */
   
   
  private function _getSpineEl()
  {
    return $this->opf_spineNode;
  }
  
  private function _getManifestEl()
  {
    return $this->opf_manifestNode;
  }

  private function _getMetadataEl()
  {
    return $this->opf_metadataNode;
  }
  
  private function _getNavMapEl()
  {
    return $this->ncx_navMapNode;
  }
   
   // TODO all should return lists
   
   public function getTitle()
  {
    return $this->getDcTitle();
  }
  
  public function getPrimaryId()
  {
     return $this->uniqIDval;
  }
  
  public function removeInvalidLangs()
  {
    // scan metadata for invalid language specification
    // and remove offending nodes
    
    $langels = $this->opf_manifestNode->getElementsByTagName('dc:language');
    if($langels) {
      foreach($langels as $lang) {
        if(!preg_match('/^[a-z][a-z]\-[A-Z][A-Z]$/', $lang->nodeValue)) {
          $this->opf_manifestNode->removeChild($lang);
          //error_log('epub:removed invalid language specifier');
        }
      }
    }
    $langels = $this->opf_manifestNode->getElementsByTagName('language');
    if($langels) {
      foreach($langels as $lang) {
        if(!preg_match('/^[a-z][a-z]\-[A-Z][A-Z]$/', $lang->nodeValue)) {
          $this->opf_manifestNode->removeChild($lang);
         // error_log('epub:removed invalid language specifier');
        }
      }
    }
    $langels = $this->opf_manifestNode->getElementsByTagName('opf:language');    
    if($langels) {
      foreach($langels as $lang) {
        if(!preg_match('/^[a-z][a-z]\-[A-Z][A-Z]$/', $lang->nodeValue)) {
          $this->opf_manifestNode->removeChild($lang);
         // error_log('epub:removed invalid language specifier');
        }
      }
    }
  }
  
  public function hasLanguage()
  {
    // is there a node in metadata specifying language?
    
    $haslang = false;
    $langels = $this->opf_manifestNode->getElementsByTagName('dc:language');
    if($langels) {
      if($lang = $langels->item(0)) {
        $haslang = true;
      } else {
         $langels = $this->opf_manifestNode->getElementsByTagName('language');
         if($langels) {
            if($lang = $langels->item(0)) {
              $haslang = true;
            }
         }
      }
    }
    return $haslang;
  }
  
  public function getLanguage()
  {
    // returns first language matched from metadata
    $langels = $this->opf_manifestNode->getElementsByTagName('dc:language');
    if($langels) {
      return $langels->item(0)->nodeValue;
    } else {
      $langels = $this->opf_manifestNode->getElementsByTagName('language');
      if($langels) {
        return $langels->item(0)->nodeValue;
      }
    }
    return $this->xmllang;
  }
   
  public function getAuthor()
  {
    return $this->getDcCreator();
  }
   
   public function getMetaPairs()
   {
      // returns raw key val pairs from the meta node
      $pairs = array();      
      foreach($this->opf_metadataNode->childNodes as $child) {
         if($child->nodeType==1) {
            $pairs[$child->nodeName] = $child->nodeValue;
         }
      }
      return $pairs;
      
   }
        
  
  public function getDcTitle()
  {
     $q = $this->opfXP->query('//dc:title', $this->opf_metadataNode);
      //$tnode = $this->opf->getElementsByTagName('dc:title')->item(0);  
      if($q->length>0) {
      return $q->item(0)->nodeValue;
    } else {
      return 'Unknown Title';
    }
      
  }
  
  public function getDcCreator()
  {
    if($q = $this->getDcCreators()) {
      $default = $q->item(0)->nodeValue;
         $list = array();
         foreach($q as $cr) {
            if($cr->getAttribute('role')=='aut') {
               $list[] = $q->nodeValue;
            }
         }
         if(count($list)>0) {
            return implode(', ',$list);
         } else {
            return $default;
         }
    } else {
      return 'Unknown Creator';
    }
  }
  
   public function getDcCreators()
   {
      $q = $this->opfXP->query('//dc:creator', $this->opf_metadataNode);
    if($q->length > 0) {
      return $q;
    } else {
         return null;
      }
   }
   
  public function getNcxTitle()
  {
    $q = $this->ncxXP->query('//nc:docTitle/nc:text');
    if($q->length > 0) {
      return $q->item(0)->nodeValue;  
    } else {
      return 'Unknown Title';
    }
  }

   public function getCoverMeta()
   {
      // not really part of the standard,
      // but becoming standard practice way
      // to include covers.
      //error_log('looking for cover meta...');
      //returns meta value for cover image id, if there is one
      foreach($this->opf_metadataNode->getElementsByTagName('meta') as $meta) {
         if($meta->getAttribute('name')=='cover') {
            return $meta->getAttribute('content');
         }
      }
      return false;
   }
   
   public function getOpfDoc()
   {
      // return the domdocument for the opf file
      
      return $this->opf;
   }

   public function getNcxDoc()
   {
      // return the domdocument for the ncx file
      
      return $this->ncx;
   }
   
   public function getDescription()
   {
     
     if($ds = $this->opf_metadataNode->getElementsByTagName('description')) {
       if($ds->item(0)) {
         return $ds->item(0)->nodeValue;
       }
     }
     return null;
     
     /*
    $q = $this->opfXP->query('//dc:description', $this->opf_metadataNode);
    if($q->length > 0) {
      return $q->item(0)->nodeValue;
    } else {
         return null;
      }
      */
   }
   
   public function getRights()
   {
      $q = $this->opfXP->query('//dc:rights', $this->opf_metadataNode);
    if($q->length > 0) {
      return $q->item(0)->nodeValue;
    } else {
         return null;
      }
   }   
   
   /**
   
      Getters for OPS files and metadata reference hashes
   
   */
   

   /** Getters for OPS files -- full XML content */
   /** Useful for rolling your own functions */
   
    
  public function dumpOcfFile()
  {
    return file_get_contents($this->ocf_filename);
  }
   
  public function getMimetypeFile()
  {
    $st = @stat($this->mimetypepath);
    return array('relpath'=>'mimetype', 'content'=>file_get_contents($this->mimetypepath), 'stat'=>$st);
  }
  public function getContainerXML()
  {
    //$this->logerr('getContainer');
    $st = @stat($this->metapath.'/container.xml');
    return array('relpath'=>'META-INF/container.xml', 'content'=>file_get_contents($this->metapath.'/container.xml'), 'stat'=>$st);
  }
  public function getOpfRaw()
  {
    $opf = $this->getOpfXML();
    return $opf['content'];
  }
  public function getOpfXML()
  {
    //$this->logerr('getOpf');
    $st = @stat($this->packagepath . '/'. $this->opfpath);
    return array('relpath'=>$this->opfpath, 'content'=>file_get_contents($this->packagepath . '/'. $this->opfpath), 'stat'=>$st);
  }
    
  public function getSpineXML()
  {
    if(@!$this->opf) return null;
    return $this->opf_spineNode;
  }
  
   public function getItemXML($filename)
   {
      $itemarray = $this->getItemByPath($filename);        
      if(count($itemarray)>0) {
         return $itemarray['content'];
      } else {
       // error_log('epub:item has no keys');
         return '';
      }
   }

   public function getItemFilepath($item)
   {
      return $this->packagepath . '/'. dirname($this->opfpath) . '/'. $item['href'];
   }
   
  public function getNcxXML()
  {
    $this->logerr('getNcx');
    $st = stat($this->packagepath . '/'. $this->ncxpath);
      //error_log($this->ncxpath);
      //error_log(print_r($st,true));
      if(!$con = file_get_contents($this->packagepath . '/'. $this->ncxpath)) {
        // error_log('could not get file contents from '.$this->packagepath . '/'. $this->ncxpath);
      }
    return array('relpath'=>$this->ncxpath, 'content'=>$con, 'stat'=>$st);
  }

  public function getOpfFilename()
  {
    $res = $this->testOPS();
    return ($res[0]=='pass') ? $res[1]['opf'] : null;
  }
  
  public function getContainerFilename()
  {
    return 'META-INF/container.xml';
  }
  
  public function getMimetypeString()
  {
    return 'application/epub+zip';
  }
  
  public function getMimetypeFilename()
  {
    return 'mimetype';
  }

   
   /**
      Getters that return arrays about content documents and other OPS
      content structure, or return the contents of those files
   */
   
  public function getItemRefs()
  {
    $encode=true;
    $r = array();
    foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
      $r[]=$this->_itemElToArray($item, $encode);
    }
    return $r;
  } 
  public function getItemFiles()
  { //WARNING: mem intensive!!
    $encode=true;
    $r = array();
    foreach($this->opf_manifestNode->getElementsByTagName('item') as $item) {
      $r[]=$this->_itemElToFullArray($item, $encode);
    }
    return $r;
  }

  public function getSpineItems()
  {
    // dereferences items in spine and returns array of hashes
    // corresponding to the items from the manifest

    
    $items = array();

    // ASM: for some stupid reason, the Xpath expressions 
    // '//itemref' and '//opfns:itemref' both fail to return
    // anything but an empty nodeset. However, '//*' returns
    // all the nodes in the doc, which then lets us filter
    // out the itemref elements by iterating. Asinine, but
    // only other way would be getElementsByTagName and
    // getElementById, which would be slower, I think.
    
      
      $els = $this->opf_spineNode->getElementsByTagName('itemref');
      
      //error_log($els->length." itemrefs in spine");

      foreach($els as $ref) {
         
         $item = $this->opfXP->evaluate('//item[@id="'.$ref->getAttribute('idref').'"]', $this->opf_manifestNode)->item(0);

         //$item = $this->opf->getElementById($ref->getAttribute('idref'));
         
       if(!$item) {
            set_time_limit(30);
            $its = $this->opf_manifestNode->getElementsByTagName('item');
            foreach($its as $it) {
               if($it->getAttribute('id')==$ref->getAttribute('idref')) {
                  $item = $it;
                  break;
               }
            }       
         }
         if(!$item) {
            throw new Exception('Could not correctly index the items in this package (ID from IDREF not found)');
         }
         $itemarray = $this->_itemElToArray($item);
         $itemarray['linear'] = $ref->getAttribute('linear');
       $items[] = $itemarray;
      }
      
      
      /*
      
      
      
      
    $els = $this->opfXP->evaluate('//*', $this->opf_spineNode);
    foreach($els as $ref) {
      if($ref->nodeType==1 && $ref->nodeName=='itemref') {
        $item = $this->opfXP->evaluate('//*[@id="'.$ref->getAttribute('idref').'"]', $this->opf_manifestNode)->item(0);
        if($item) {
               $itemarray = $this->_itemElToArray($item);
               $itemarray['linear'] = $ref->getAttribute('linear');
          $items[] = $itemarray;
        }
      }
    }
      */
    return $items;
  }
  
  public function getNavPoints($appendguide=false)
  {
    // returns an array of hashes representing
    // each navPoint element in the NCX
    return $this->_getNavPoints($this->ncx_navMapNode, $appendguide);
  }
  

  public function getAsSimpleHash()
  {
    /**
      Returns a simple (flat) hashed array of the whole contained structure
      Useful for making your own zips or hand-rolling edit functions
    */
    $S = '';
    $opf = $this->getOpfXML();
    $container = $this->getContainerXML();
    $S[$this->getMimetypeFilename()]=$this->getMimetypeString();
    $S[$container['relpath']]=$container['content'];
    $S[$opf['relpath']]=$opf['content'];
    foreach($this->getItemFiles() as $item) {
      $S[$item['href']]=$item['content'];
    }
    return $S;
  }

    
   public function getCoverMetaWithImage()
   {
      if($id = $this->getCoverMeta()) {
         //error_log('cover meta is specified as id '.$id);
         $item = $this->getItemHashById($id);
         //error_log('got item from manifest:'.print_r($item,true));
         return $item;
      } else {
         return false;
      }
   }
   


  private function _getNavPoints($navPoint, $appendguide=false)
  {
      // this may be called recursively from _navElToArray()
      // note: dont use appendguide on recursive calls
      
      // $appendguide: whether to append the guide
      // as a final nav element with a nested
      // list of guide items to content docs
      
    $navs = array();
      if(!is_object($navPoint)) return $navs;
    $children = $navPoint->childNodes;
    foreach($navPoint->childNodes as $c) {
         if($c->nodeType==1) { /* match exactly, or account for namespace */
            if($c->nodeName=='navPoint' || preg_match('/\:navPoint$/',$c->nodeName)) {
               $navs[] = $this->_navElToArray($c);
            }
         }
      }
      if($appendguide) {
         if($guide = $this->opf->getElementsByTagName('guide')) {
            if($g = $guide->item(0)) {
               if($refs = $g->getElementsByTagName('reference')) {
                  if($refs->length>0) {
                     $order = count($navs)+1; 
                     $guidenav = array('id'=>'autoguide'.time(),
                        'playOrder'=>$order,
                        'class'=>'guide',
                        'label'=>'Guide to Contents',
                        'src'=>'',
                        'navPoints'=>array()
                        );  
                     $guidenps = array();
                     $firstitem = true;
                     for($i = 0; $i < $refs->length; $i++) {
                        $order++;
                        $ref = $refs->item($i);
                        $refnav = 
                        array(
                        'id'=>'guideref'.uniqid(),
                        'playOrder'=>$order,
                        'class'=>'guideref',
                        'label'=>$ref->getAttribute('title'),
                        'src'=>$ref->getAttribute('href'),
                        'navPoints'=>array()
                        );
                        if($firstitem) {
                           $guidenav['src'] = $ref->getAttribute('href');
                           $firstitem = false;
                        }
                        $guidenps[] = $refnav;

                     }
                     $guidenav['navPoints'] = $guidenps;
                     $navs[] = $guidenav;
                  }
               }
            }
         }
         
      }
      return $navs;
      /*
      if(!is_object($navPoint)) return $navs;
      foreach($navPoint->getElementsByTagName('navPoint') as $np) {
         $navs[] = $this->_navElToArray($np);
      }
    return $navs;
      */
  }
  private function _navElToArray($nav)
  { // converts a navPoint element
    // to an array of hashes, including
    // nested navPoints
      if(!is_object($nav)) {
         throw new Exception("that is not an object");
      }
       //  return array();
       $src = (is_object($this->ncxXP->evaluate('nc:content',$nav)->item(0))) ? $this->ncxXP->evaluate('nc:content',$nav)->item(0)->getAttribute('src') : null;
    return array('id'=>$nav->getAttribute('id'),
    'playOrder'=>intval($nav->getAttribute('playOrder')),
    'class'=>$nav->getAttribute('class'),
    'label'=>str_replace('\\', '\\\\', $this->ncxXP->evaluate('nc:navLabel', $nav)->item(0)->textContent),
    'src'=>$src,
    'navPoints'=>$this->_getNavPoints($nav)
    );
  }

  public function navElToArray($nav)
   {
      
      return $this->_navElToArray($nav);
   
   }
   
  private function _getItemFullpath($id)
  {
    //$this->_speedTestGetItemEl($id);
    //$this->logerr('seeking id '.$id);
    if(!$this->opfXP) return null;
    if(!($el = $this->_getItemElById($id))) {
      //$this->logerr('cannot find id, maybe xpath parser is broken');
      if($el = $this->opf->getElementById($id)) {
        //$this->logerr('okay, found it the old fashioned way');
      } else {
        //$this->logerr('still could not find it, assuming an error');
        return false;
      }
    }
    return $this->opspath . '/'. $this->getOpsStem() . '/' . $el->getAttribute('href');
  }
  
   public function getOpsStem()
   {
    return (strlen($this->opsrel)) ? $this->opsrel : '';
    
   }
   
  private function _itemElToArray($item)
  {
    if(!is_object($item)) {
      //error_log('BookGluttonEpub: passed data is not an object');
      return null;
    }
    return array('id'=>$item->getAttribute('id'),
                      'href'=>$item->getAttribute('href'),
                      'media-type'=>$item->getAttribute('media-type'),
                      'fallback'=>$item->getAttribute('fallback'));
  }

  private function _itemElToFullArray($item, $encode=false, $encode_non_binary=false, $recurse=false)
  {
    //$this->logerr('itemElToFullArray');
    // intensive!! don't call this on iterations unless you have to
    return $this->_itemElAppendContent($this->_itemElToArray($item), $encode, $encode_non_binary, $recurse);
  }
  
  private function _itemElAppendContent($item, $encode=true, $encode_non_binary=false, $recurse=false)
  {
    // Takes an item ARRAY, not ELEMENT! Convert to array first with _itemElToArray

    $item['content'] = '';
    $cunt = @file_get_contents($this->packagepath . '/'. $this->opsrel . '/'. $item['href']);
    
    if(!$cunt) {
      //error_log('error getting file '.$this->packagepath . '/'. $this->opsrel . '/'. @$item['href']);
    }
    
    if(($this->_isBinaryType($item['media-type'])||$encode_non_binary) && $encode==true && $cunt) {
      $item['content'] = chunk_split(base64_encode($cunt), 76, "\n");
    } else if($cunt) {
      $item['content'] = $cunt;
    }
    if($this->_isBinaryType($item['media-type'])) {
      $item['imginfo'] = getimagesize($this->packagepath . '/'. $this->opsrel . '/'. $item['href']);
    }

    // filter out processing instructions
    $item['content'] = $item['content'];
    if($recurse) { // will be slow, use judiciously
      $item['content'] = $this->_encodeItemAssets($item['content']);
    }

    $item['length'] = strlen($item['content']);
    $stat = @stat($this->packagepath . '/'. $this->opsrel . '/'.$item['href']);
    $item['updated'] = $stat['mtime'];
    return $item;
  }
  
  private function _encodeItemAssets($itemcontent)
  {
    // takes a FULL item ARRAY!! Convert to array first with _itemElToArray
    // then makes sure to append content, with _itemElAppendContent
    // this will give you the full item array
    // pull in images encoded as data uris
    
    // modifes the 'content' value in array 
    
  
    return preg_replace_callback('/<img(.*?)src\s*?=\s*?"(.+?)"([^>]*?)>/m', array($this, '_encodematch'), $itemcontent);
  }
  
  private function _encodematch($matches) {
    //error_log('matched image tag:'.$matches[0]);

    $itemel = $this->_getItemElByPath($matches[2]);
    if(!$itemel) {
      //error_log('not found');
      return $matches[0];
    }
    $cob = $this->_itemElAppendContent($this->_itemElToArray($itemel), true, true, true);
    $url = 'data:'.$cob['media-type'].';base64,'.$cob['content'];
    $dims = "";
    if(!preg_match('/height/i', $matches[0])) {
      $dims = 'height="'.$cob['imginfo'][1].'" ';
      //error_log($dims);
    }
    return '<img'.$matches[1].'src="'.$url.'" '.$dims.$matches[3].'>';
  }

  private function _getItemDataUrl($item, $recurse=false)
  {
    // Takes an item ARRAY, not ELEMENT! Convert to array first with _itemElToArray
    
    $cob = $this->_itemElAppendContent($item, true, true, true);
    $ret = 'data:'.$cob['media-type'].';base64,'.$cob['content'];
    return $ret;
  }


  private function _queryOPF($expr)
  {
    
  }
   
   
   
   
   
   
   
   /**
   
      Get things by referencing the OPF id for the item
      
   */
   
  public function getItemHref($id)
  {
    if(!$this->opfXP) return null;
    return $this->opfXP->evaluate('//*[@id="'.$id.'"]')->item(0)->getAttribute('href');
  }
  
  public function getItemType($id)
  {
    if(!$this->opfXP) return null;
    
    return $this->opfXP->evaluate('//*[@id="'.$id.'"]')->item(0)->getAttribute('media-type');
  }

  public function getItemHashById($id)
  {
    return $this->_itemElToFullArray($this->_getItemElById($id), false); // return binaries unencoded
  }
  
  public function getItemById($id)
  {
    /* different way to pull a manifest item */
    // this one just returns the contents as a
    // string, unlike the meta-enabled funcs 
    
    if($path = $this->_getItemFullpath($id)) {
      //$this->logerr('full path to this is:'.$path);
      return @file_get_contents($path);
    } else {
      return '';
    }
  }

   private function _getItemElById($id)
  {
          if(!$this->opfXP) return null;
    return $this->opfXP->evaluate('//*[@id="'.$id.'"]')->item(0);
  }

   
   /**
      Get or check things by passing the path of the item
   */
   
  public function hasItemByPath($path)
  { //alias
    return $this->hasItemByHref($path);
  } 
  
  public function hasItemByHref($path)
  {
    $item = $this->_getItemElByPath($path);
    return ($item) ? true : false; 
  }
  
  public function getItemByPath($path)
  {
    // returns first item matching the rel url
    // appends contents of file to hashed array
    
    
    $item =  ($itemel = $this->_getItemElByPath($path)) ? $this->_itemElToFullArray($itemel, false, false, false) : array();
    //error_log('epub class logging item array');
    //error_log(print_r($item,true));
    return $item;
  }
  
  public function getItemByPathWithAssets($path)
  {
    // returns first item matching the rel url
    // appends contents of file to hashed array
    // base64 encodes all external assets
    return ($itemel = $this->_getItemElByPath($path)) ? $this->_itemElToFullArray($itemel, false, false, true) : array();
  }
   
  private function _getItemElByPath($path)
  {
   // error_log('epub class getting '.$path);
    
    if(!$this->opfXP) {
      //error_log('no xpath parser defined');
      return null;
    } else {
      return $this->opfXP->evaluate('//*[@href="'.$path.'"]',$this->opf_manifestNode)->item(0);
    }
  }
    
   public function getFlatNav()
   {
      // returns all navpoints in document order, flat structure
      return $this->ncx->getElementsByTagName('navPoint');
   }
   
   public function getNavLabelByHref($href)
   { 
     $found = null;
     $navpoints = $this->getFlatNav();
     foreach($navpoints as $np) {
        $arr = $this->_navElToArray($np);
        if($arr['src']==$href) {
           $found = $arr['label'];
           break;
        }
     }
     if(!$found && strpos($href,'#')) {
        $hrefparts = explode('#', $href);
        return $this->getNavLabelByHref($hrefparts[0]);
     }
     
     return $found;
   }
   
   /**
   
   Factories - create doms and dom elements
   
   */
   
    
   
   
  private function _makeContDoc($xml = null)
  {
    $contdoc = new DomDocument('1.0', 'utf-8');
    $contdoc->preserveWhiteSpace = FALSE;

    if($xml == null) {
      $contstr = '<?xml version="1.0" encoding="UTF-8" ?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"></container>';
      $contdoc->loadXML($contstr);
      $rootfiles = $contdoc->appendChild($contdoc->createElement('rootfiles'));
      $rootfile = $contdoc->createElement('rootfile');
      $rootfile->setAttribute('full-path', $this->opfpath);
      $rootfile->setAttribute('media-type', $this->opsmime);
      $rootfiles->appendChild($rootfile);
      $contdoc->getElementsByTagName('container')->item(0)->appendChild($rootfiles);      
    } else {
      //error_log($xml);
      @$contdoc->loadXML($xml);
      if(!$contdoc) {
        throw new Exception('content xml is not properly formed');
      }
    }
    return $contdoc;
  }
  
  private function _buildNcxDoc($ncx = null)
  {
      if(!$ncx) {
          $ncx = new DomDocument('1.0', 'utf-8');
          $ncx->preserveWhiteSpace = FALSE;  
      }
      $ncx->loadXML('<?xml version="1.0" encoding="UTF-8" ?><!DOCTYPE ncx PUBLIC "'.$this->doctypeNISO.'" "'.$this->daisydtd.'"><ncx version="2005-1" xml:lang="en-US" xmlns="'.$this->ncxNS.'"></ncx>');
      $n = $ncx->getElementsByTagName('ncx')->item(0);
      $this->ncx_headNode = $n->appendChild($ncx->createElement('head'));

      $this->ncx_docTitleNode = $n->appendChild($ncx->createElement('docTitle'));
      $this->ncx_docTitleNode->appendChild($ncx->createElement('text'))->appendChild($ncx->createTextNode(' '));
      $this->ncx_docAuthorNode = $n->appendChild($ncx->createElement('docAuthor'));     
      $this->ncx_docAuthorNode->appendChild($ncx->createElement('text'))->appendChild($ncx->createTextNode(' ')); 
      
      $this->ncx_navMapNode = $n->appendChild($ncx->createElement('navMap'));
      // add required legacies (except uid which we add later)
      $this->ncx_headNode->appendChild($this->_ncxMeta('dtb:depth', '1', $ncx));
      $this->ncx_headNode->appendChild($this->_ncxMeta('dtb:totalPageCount', '0', $ncx));
      $this->ncx_headNode->appendChild($this->_ncxMeta('dtb:maxPageNumber', '0', $ncx));
      // TODO remove the following--should not get called here!
      $this->_createNcx(); // add to manifest
      $this->ncxXP = new DomXpath($ncx);
      $this->ncxXP->registerNamespace("nc", $this->ncxNS); 
      return $ncx;
  }

  public function makeNcxDoc($xml)
  {
    return $this->_makeNcxDoc($xml);
  }
       
  private function _makeNcxDoc($xml = null)
  {
    $ncx = new DomDocument('1.0', 'utf-8');
    $ncx->preserveWhiteSpace = FALSE;      

    if($xml==null) {

      $ncx = $this->_buildNcxDoc($ncx);      

    } else {

      @$ncx->loadXML($xml);
      
 
      $n = $ncx->getElementsByTagName('ncx')->item(0);

      if(!is_object($n)) {
        //error_log('epub:ncx not found, could not parse dom structure from it');
        $ncx = $this->_buildNcxDoc($ncx);
      }
      
      $this->ncx_headNode = $n->getElementsByTagName('head')->item(0);
      $this->ncx_docTitleNode = $n->getElementsByTagName('docTitle')->item(0);
      $this->ncx_docAuthorNode = $n->getElementsByTagName('docAuthor')->item(0);
      $this->ncx_navMapNode = $n->getElementsByTagName('navMap')->item(0);
      /*
      //if(!$this->ncx_docAuthorNode) {
      //  $this->ncx_docAuthorNode = $n->insertBefore($ncx->createElement('docAuthor'), $n->firstChild);        
      //}
      */
      if(!$this->ncx_docTitleNode) {
        $this->ncx_docTitleNode = $n->insertBefore($ncx->createElement('docTitle'), $n->firstChild);        
      }     
      
      
      if(!$this->ncx_headNode) {
        // in theory, this should be a critical failure, per the spec
        // the reality is that many books may not have this set
        // to comply, we create it
        $this->ncx_headNode = $n->insertBefore($ncx->createElement('head'), $n->firstChild);
      }
      if(!$this->ncx_navMapNode) {
        throw new Exception('the ncx structure is incomplete:'.$ncx->saveXML());
      }
      
      $this->ncxXP = new DomXpath($ncx);
      $this->ncxXP->registerNamespace("nc", $this->ncxNS);
      
      
      //$ncw = $this->_parseNcx($xml);
      //$ncx = $this->_rebuildNcx($ncw);
      
    }
    // set up xpath parser

    return $ncx;
  }
  
  public function makeOpfDoc($xml)
  {
    return $this->_makeOpfDoc($xml);
  }
  
  private function _makeOpfDoc($xml = null)
  {
    $opf = new DomDocument('1.0','utf-8');
    $opf->preserveWhiteSpace = FALSE;
    $opf->validateOnParse = true;

 
    if ($xml==null) {
      $xml = '<?xml version="1.0" encoding="UTF-8" ?><package version="'.$this->packageVersion.'" unique-identifier="'.$this->uniqIDscheme.'" xmlns="'.$this->opfNS.'"></package>';
      $opf->loadXML($xml);
      $package = $opf->getElementsByTagName('package')->item(0);
      // multiple namespaces on an element require us to use document fragments from strings      
      $frag = $opf->createDocumentFragment();
      if(!$frag->appendXML('<metadata xmlns:dc="'.$this->dcNS.'" xmlns:opf="'.$this->opfNS.'" />')) {
        throw new Exception('could not create metadata fragment'."\n");
      }
      $this->opf_metadataNode = $package->appendChild($frag);
      $this->opf_manifestNode =$package->appendChild($opf->createElement('manifest'));
      $this->opf_spineNode = $package->appendChild($opf->createElement('spine'));
    } else {
      
      // fix a typo bug that infected many BG packages (we
      // do it this way because php wont let us modify the
      // xmlns attribute after parsing:
      
      $xml = str_replace('http://www.idpf.og/2007/opf', 'http://www.idpf.org/2007/opf', $xml);
      
      
      @$opf->loadXML($xml);
      $package = $opf->getElementsByTagName('package')->item(0);
            if(!is_object($package)) {
                //error_log('no package element found');
                throw new Exception('package element missing or malformed');
            
            }
      if($package->hasAttribute('xmlns')) {
        if($package->getAttribute('xmlns')!=$this->opfNS) { // correct this
          
          // this won't work due to a bug in PHP, but whatever
          // maybe someday some asshole will fix it.
          
          $package->setAttribute('xmlns', $this->opfNS);
          
          // should maybe save but holding off on that
          
          //$opf->formatOutput = TRUE;
          //$opf->save($this->packagepath . '/'.$this->opfpath);
        } else {
          //error_log($package->getAttribute('xmlns'));
        }
      } else {
        //error_log('package has no xmlns attribute!!');
      }
      
      if($package->getElementsByTagName('dc-metadata')->length > 0) {
        // old-style meta
        $this->opf_metadataNode = $package->getElementsByTagName('dc-metadata')->item(0);
      } else {
        $this->opf_metadataNode = $package->getElementsByTagName('metadata')->item(0);
      }
      $this->opf_manifestNode = $package->getElementsByTagName('manifest')->item(0);

      
      
      
       $this->uniqIDscheme = $package->getAttribute('unique-identifier');
       
       if(!$this->uniqIDscheme) {
          $this->uniqIDscheme = 'PrimaryID';
          $package->setAttribute('unique-identifier', $this->uniqIDscheme);
       }
       
       if(!$opf->getElementsByTagName('identifier')->item(0)) { // no identifiers at all!!
           $newval = 'urn:uuid:'.uuidGen::generateUuid();
           $id = $this->opf_metadataNode->appendChild($opf->createElement('identifier'));
           $id->appendChild($opf->createTextNode($newval));
           $id->setAttribute('id', $this->uniqIDscheme);
           $this->uniqIDval = $newval;
       }
       
       foreach($opf->getElementsByTagName('identifier') as $id) {
          if($id->getAttribute('id')==$this->uniqIDscheme) {
             $this->uniqIDval = trim($id->nodeValue);
          }
       }
       
       if(!strlen($this->uniqIDval)>1) {
          throw new Exception("EPUB file must have a globally unique identifier such as a GUID.");
       }
         
      $this->opf_spineNode = $package->getElementsByTagName('spine')->item(0);
      if(!$this->opf_metadataNode || !$this->opf_manifestNode || !$this->opf_spineNode) {
        throw new Exception('this opf structure is incomplete');
      }
    }
    

    
    $this->opfXP = new DomXpath($opf);
    $this->opfXP->registerNamespace("opfns", $this->opfNS);
    $this->opfXP->registerNamespace("dc", $this->dcNS);
    return $opf;
  }
  
   
  private function _setNavLabel($node, $labeltext)
  {
    // creates and returns a navLabel with the given text
    
    $label = $this->ncx->createElement('navLabel');
    $text = $this->ncx->createElement('text');
    $domtext = new DOMText($labeltext);
    $text->appendChild($domtext);
    $label->appendChild($text);
    $node->appendChild($label);
    return $node;
  }
  
  private function _createNcx()
  {
    // creates the ncx, adding a ref to it in the spine el
    // also gives its nav map a label
    
    $this->addItem('ncx', $this->ncxpath, $this->ncxmime, null);
    $this->_getSpineEl()->setAttribute('toc', 'ncx');
    // the following causes validation errors:
    //$this->_setNavLabel($this->_getNavMapEl(), $this->navmaplabel); 
  }
  
  private function _ncxMeta($aname, $aval, $ncx = null)
  {
    // creates a meta el for the ncx
    if($ncx==null) $ncx = $this->ncx;
    $meta = $ncx->createElement('meta');
    $meta->setAttribute('name', $aname);
    $meta->setAttribute('content', $aval);
    return $meta;
  }
  
  private function _createNavPoint($id, $heading=null, $src=null, $class='section')
  {
    // creates a nav point for the navMap and returns it
    
    if($this->ncx->getElementById($id)) {
      throw new Exception('item with id '.$id.' already exists in the ncx');
    }
    $playorder = $this->_getNavMapEl()->getElementsByTagName('navPoint')->length + 1;
    //$this->logerr('playorder will be '+$playorder);
    $navpoint = $this->ncx->createElement('navPoint');
    $navpoint->setAttribute('id', $id);
    $navpoint->setIdAttribute('id', true);
    $navpoint->setAttribute('playOrder', $playorder);
    $navpoint->setAttribute('class', $class);
      if($heading && $src) {
         $navpoint = $this->_setNavLabel($navpoint, $heading);
         $content = $this->ncx->createElement('content');
         $content->setAttribute('src', $src);
         $navpoint->appendChild($content);
      }
    return $navpoint;
  }
  
  private function _createItem($id, $href, $mediatype)
  {
    // creates an item for the manifest el and returns it
    
    if($this->opf->getElementById($id)) {
      throw new Exception('item with id '.$id.' already exists in the manifest');
    }
    $item = $this->opf->createElement('item');
    $item->setAttribute('id', $id);
    $item->setIdAttribute('id', true);
    $item->setAttribute('href', $href);
    $item->setAttribute('media-type', $mediatype);
    return $item;
  }
  
  private function _createItemRef($idref, $linear)
  {
    // creates an itemref for the spine el and returns it
    // returns an unattached itemref element
      
    if($this->opf->getElementById($idref)) { // requires item to exist already
      $itemref = $this->opf->createElement('itemref');
      $itemref->setAttribute('idref', $idref);
      $itemref->setAttribute('linear', $linear);
      return $itemref;
    } else {
      throw new Exception('itemrefs require items with that id to exist, and '.$idref.' no existy');
    }   
  }

   
   
   /**
   Builders -
      some look like simple setters but
      they are actually additive
    */
   
   
     
   public function addTitle($title)
  {
    // stub: will add another dctitle el
      $this->setMeta('title',$title);
  }
  
  public function setTitle($title)
  {
          if(!$this->opfXP) return null;
      $q = $this->opfXP->query('//dc:title', $this->opf_metadataNode);
      if($q->length>0) {
         foreach($q as $node) {
            $node->parentNode->removeChild($node);
         }
      }
      $this->addTitle($title);
    $this->title = $title;
  }
  
  public function addAuthor($author)
  {
      $this->setMeta('creator', $author, 'aut');
  }
  
  public function setAuthor($author)
  {
      // purge any existing with role 'aut'
      if($q = $this->getDcCreators()) {   
         foreach($q as $cr) {
            if($cr->getAttribute('role')=='aut') {
               if($cr->parentNode->removeChild($cr)) {

               }
            }
         }
    }
      $this->addAuthor($author);
  }
   
   public function setIsbn($isbn)
   {
      $this->_setIdentifier($isbn, 'isbn', false);
   }
   
   public function setPublisher($pub)
  {
    $this->addMeta('publisher', $pub);
  }
  public function setDescription($des)
  {
      $this->addMeta('description', $des);
  }
  public function setOriginalPubdate($date)
  {
      $this->addMeta('date', $date, 'original-publication');
  }
  public function setOpsPubdate($date)
  {
      $this->addMeta('date', $date, 'ops-publication');      
  }
  public function setLanguage($lang)
  {
      $this->addMeta('language', $lang);
  }
  public function addSubject($sub)
  {
      $this->addMeta('subject', $sub);
  }
  public function setRights($rights)
  {
      $this->addMeta('rights', $rights);
  }

   public function getIsbn()
   {
     foreach($this->opf->getElementsByTagName('identifier') as $id) {
        if(strtolower($id->getAttribute('scheme'))=='isbn' || strtolower($id->getAttribute('scheme'))=='isbn13') {
           return $id->nodeValue;
        }
     }
     return null;
   }
   
   public function replacePrimaryIdValue($newval)
   {
      if(!$this->opf->getElementsByTagName('identifier')->item(0)) {
         $id = $this->opf_metadataNode->appendChild($this->opf->createElement('identifier'));
         $id->appendChild($this->opf->createTextNode($newval));
         $id->setAttribute('id', $this->uniqIDscheme);
         $this->uniqIDval = $newval;
      } else {

         foreach($this->opf->getElementsByTagName('identifier') as $id) {
            if($id->getAttribute('id')==$this->uniqIDscheme) {
               foreach($id->childNodes as $child) {
                  $id->removeChild($child);
               }
               $id->appendChild($this->opf->createTextNode($newval));
               $this->uniqIDval = $newval;
            }
         }
      
      }
   }
   

   
  private function _setIdentifier($content=null, $scheme=null, $isprimary=true)
  {
      $uuid=null;
      if(!$content) {
         $uuid = uuidGen::generateUuid();
         $scheme = "URN";
         $content = 'urn:uuid:'.$uuid;
      }
      $dcidentifier = $this->opf->createElement('dc:identifier', $content);
      if($scheme) {
            $dcidentifier->setAttributeNS($this->opfNS, 'opf:scheme', $scheme);
      }
      if($isprimary) {
        $dcidentifier->setAttribute('id', $this->uniqIDscheme);
        $this->uniqIDval = $content;
      }
      $this->opf_metadataNode->appendChild($dcidentifier);
      if($uuid) {
         $this->ncx_headNode->appendChild($this->_ncxMeta('dtb:uid', $uuid));
      } else {
         $this->ncx_headNode->appendChild($this->_ncxMeta('dtb:uid', $content)); 
      }
  }
   
   public function hasLang()
   {
     
   }
      
   public function setMeta($name, $content, $variant=null)
   {
      $this->addMeta($name, $content, $variant);
   }
   
   public function addMeta($name, $content, $variant=null)
   {

      array_push($this->dcdata, array(
         
         $name,$content,$variant
         
      ));
    // opf -- replace first if there, otherwise add
      $dcel = $this->opf->createElement('dc:'.$name, $content);
      if($variant) {
         if($name=='creator' || $name=='contributor') {
            $dcel->setAttributeNS($this->opfNS, 'opf:role', $variant);
         } else if($name=='date') {
            $dcel->setAttributeNS($this->opfNS, 'opf:event', $variant);
         }
      }
      $this->opf_metadataNode->appendChild($dcel);
   }

   public function addCoverMeta($content)
   {
      $cover = $this->opf->createElement('meta');
      $cover->setAttribute('name', 'cover');
      $cover->setAttribute('content', $content);
      $this->opf_metadataNode->appendChild($cover);  
   }
   
  public function addItem($id, $href, $mediatype, $content=null, $linear=null, $fallback=null)
  {
    // adds an item to the manifest
    //
    // if a content string is passed,
    // also creates a file from that content
    // and adds it to the zip queue
    // 
    // if linear is set to either 'yes'
    // or 'no', a spine ref will be created
    // with a linear attribute set to that value
    //
    // note: spine refs to non content docs not allowed
    
    
    $this->logerr('BookGluttonEpub->addItem:'.$href, 4);
    
    if($id=='ncx') $linear = null;
    // we don't want ncx in the main content root, keep it level with opf
    $href = (strlen($this->opsrel)>0 && $id!='ncx') ? "$this->opsrel/".$href : $href;
    $this->_getManifestEl()->appendChild($this->_createItem($id, $href, $mediatype));
    if($content) {
      
      //$this->logerr('this has content payload');
      //$this->logerr('href is '.$href);
      $path = pathinfo($href);
      //$this->logerr('relative path to this href is '.$path['dirname']);
      $relpath = $path['dirname'];
      
      
      //$this->logerr('checking for dir:'.$this->opspath . '/' . $relpath);
      
      if(!file_exists($this->opspath . '/' . $relpath)) {
        //$this->logerr('creating directory:'.$this->opspath . '/' . $relpath); 
        if(!mkdir($this->opspath . '/' . $relpath, 0755, true)) {
          $this->logerr('could not make directory:'.$this->opspath . '/' . $relpath, 0);
        }
      }
      if(!file_put_contents($this->opspath . '/' . $href , $content)) {
        $this->logerr('Could not put file:'.$this->opspath . '/'. $href, 0);      
      } else {
        $this->logerr('adding '.$this->opspath . '/' . $href.' to zip');
        $this->zipQ[$href]=$this->opspath . '/' . $href;
      }
    }
    if($linear) { // linear must be null for non-content items!
      //$this->logerr('adding spine ref:'.$id);
      $this->addSpineRef($id, $linear);
    }
  }

  public function addNavItem($id, $heading, $src, $class, $np=null)
  {
      // pass in a navpoint np to attach to that instead of navmap
    $src = (strlen($this->opsrel)>0) ? "$this->opsrel/".$src : $src;
      if(!$np) {
         $this->_getNavMapEl()->appendChild($this->_createNavPoint($id, $heading, $src, $class));
      } else {
         $np->appendChild($this->_createNavPoint($id, $heading, $src, $class));
      }
  }
  
   public function addSpineRef($idref, $linear='yes')
  {
    $this->_getSpineEl()->appendChild($this->_createItemRef($idref, $linear));
  }

  private function _addCoverItem()
  {
    if($this->includecover != false) {
      $this->addHTMLItem($this->includecover);
      //$this->logerr('adding cover item HTML:'.print_r($this->includecover, true));
      $this->includecover = false;
    }
  }
  

  public function addHTMLItem($page) {
    
    /**
      Takes an html page object, with an assets key
      that contains an array of assets for the page,
      adds page to manifest and spine and assets as
      out of spine items. Links to page in ncx with
      label. Page object is keyed array with id,
      html, and assets keys. Each asset is keyed with
      id, relpath (relative to html), mimetype, and content:
      
        id=>
        relpath=>
        mimetype=>
        content=>
      
    */
    
    $this->logerr('BookGLuttonEpub->addHTMLItem', 4);
    
    $this->addItem(
    
      $page['id'],
      $page['id'] .'.html',
      'application/xhtml+xml',
      $page['html'],
      'yes');

    $this->addNavItem($page['id'], $page['label'], $page['id'] .'.html', 'cover');

    
    if(count($page['assets'])>0) {
      foreach($page['assets'] as $asset) {
        
        $this->addItem(
          $asset['id'],
          $asset['relpath'],
          $asset['mimetype'],
          $asset['content']
        );
      }
    }
    $this->_saveMeta();
  }
  
   public function prependItem($id, $href, $mediatype, $content=null, $linear=null, $fallback=null)
  {
    // prepends an item to the manifest
          if(!$this->opfXP) return null;
    $items = $this->opfXP->evaluate('//item', $this->opf_manifestNode);
    
    
    /*
    
    insertBefore is fucking broken if you get the nodelist using XPath
    it inserts at the end of the nodelist instead of the beginning if
    you reference item(0)
    
    using getElementsByTagName returns the proper behaviors
    
    
    
    
    */
    
    
    $this->logerr($items->length.' items in manifest');
    
    $items = $this->opf->getElementsByTagName('item');
    
    $href = (strlen($this->opsrel)>0) ? "$this->opsrel/".$href : $href;
    $this->_getManifestEl()->insertBefore($this->_createItem($id, $href, $mediatype), $items->item(0));

    if($content) {
      $path = pathinfo($href);
      $relpath = $path['dirname'];
      
      if(!file_exists($this->opspath . '/' . $relpath)) {
        //$this->logerr('creating directory:'.$this->opspath . '/' . $relpath); 
        if(!mkdir($this->opspath . '/' . $relpath, 0755, true)) {
          $this->logerr('could not make directory:'.$this->opspath . '/' . $relpath);
        }
      }
      if(!file_put_contents($this->opspath . '/' . $href , $content)) {
        $this->logerr('Could not put file:'.$this->opspath . '/'. $href);     
      } else {
        //$this->logerr('adding '.$this->opspath . '/' . $href.' to zip');
        $this->zipQ[$href]=$this->opspath . '/' . $href;
      }
    }
    if($linear) { // linear must be null for non-content items!
      //$this->logerr('adding spine ref:'.$id);
      $this->prependSpineRef($id, $linear);
    }
  }
   
  public function prependHTMLItem($page) {
    
    /**
      Like above, except prepends it in the manifest,
      spine, and ncx
    */
    //$this->logerr('prepending an HTML item and its assets...');
    $this->prependItem(
    
      $page['id'],
      $page['id'] .'.html',
      'application/xhtml+xml',
      $page['html'],
      'yes');

    $this->prependNavItem($page['id'], $page['label'], $page['id'] .'.html', 'cover');
  
    if(count($page['assets'])>0) {
      foreach($page['assets'] as $asset) {
        $this->addItem(
          $asset['id'],
          $asset['relpath'],
          $asset['mimetype'],
          $asset['content']
        );
      }
    }
    //$this->logerr('saving...');
    $this->_saveMeta();
  }
  
  public function prependCover($cover)
  {
    $this->prependHTMLItem($cover);
  }

  public function prependSpineRef($idref, $linear='yes')
  {
    $this->_getSpineEl()->insertBefore($this->_createItemRef($idref, $linear), $this->opf->getElementsByTagName('itemref')->item(0));
  }

   public function prependNavItem($id, $heading, $src, $class)
  {
    $this->logerr('prependNavItem');
    $nmap = $this->_getNavMapEl();
    $src = (strlen($this->opsrel)>0) ? "$this->opsrel/".$src : $src;
    $newnav = $this->_createNavPoint($id, $heading, $src, $class);
    //$this->logerr('new nav has label '.$newnav->nodeValue);

    // DO NOT try to use the xpath parser to do this!
    
    $navs = $nmap->getElementsByTagName('navPoint');
    
    $inserted = $nmap->insertBefore($newnav, $navs->item(0));

    //$this->logerr('rebuilding');
    $this->_rebuildNcx();
    
    // TODO : regenerate ncx from scratch here, because document order differs from play order
    
  }

   public function replaceItemById($id, $replacement, $savechanges=true)
  {
      // keeps filename but replaces content of item chosen by id

      $item = $this->_getItemElById($id);
      if(!$item) {
        $this->logerr("item with id $id not found. dumping item refs:".print_r($this->getItemRefs(), true),2);
        return;
      }
      $item->setAttribute('media-type', $replacement['media-type']);
      //$item->setAttribute('fallback', $replacement['fallback']);
      if($savechanges) {
        //$this->logerr('saving changes to filesystem');
        // replace in filesystem
        if(strlen($replacement['content'])>0) {
          //$this->logerr('we do have content, so we write it');
          $itempath = $this->opspath .'/'.$item->getAttribute('href');
          if(!file_put_contents($itempath, $replacement['content'])) {
            $this->logerr('could not write replacement content to:'.$itempath,0);
          } else {
            $this->logerr('wrote replacement content to:'.$itempath, 1);
          }
        }

      }
      return $item;
  }

   
   
   
   
   /** Helpers - for file and archive operations */
   
   
   
  private function _getMimeFromExt($src)
  {
    $pi = pathinfo($src);
    @$ext = $pi['extension'];
    switch(strtolower($ext))
    {
      case 'svg':
        return 'image/svg+xml';
      case 'png':
        return 'image/png';
      case 'jpg':
        return 'image/jpeg';
      case 'jpeg':
        return 'image/jpeg';
      case 'gif':
        return 'image/gif';
      case 'ttf':
        return 'application/x-font-ttf';
      case 'otf':
        return 'application/x-font-otf';          
      case 'xml':
        return 'application/xml';
      case 'html':
        return 'application/xhtml+xml';
      case 'xhtml':
        return 'application/xhtml+xml';
      case 'htm':
        return 'application/xhtml+xml';
      case 'pdf':
        return 'application/pdf';
      case 'css':
        return 'text/css';
      case 'swf':
        return 'application/x-shockwave-flash';
      default:
        return 'application/octet-stream';
    }
  }
  
   
   
  private function _getRelPathWithOpf($root)
  {
    // given an opf full-path from content.xml, this returns the
    // relative path portion and the filename
    
    return $this->_getRelPathToContent($root);

  }

  private function _getRelPathToContentRelToOpf($cfile)
  {
    // determines the relative path to a content file as
    // the inclusion between the relpath to opf and the
    // path to the content file (eg node steps in the tree)
    // between the two of them, as a relative path

    
    $rel = $this->_getRelPathToContent($cfile);

    if(@$this->relpath==='' || @$this->relpath===null) return $rel[0];
        
    $thispath = explode('/',$rel[0]);   
    $opfpath = explode('/', $this->relpath);
    

    
    return implode('/',array_diff($thispath, $opfpath));
    
  
  } 
  
  private function _getRelPathToContent($root)
  {
    // determines the relative path to a content file
    $this->logerr('trying to determine relative path to '.$root, 2);
    $pi = explode('/', $root); // utf8 filenames okay
    $ret = array();
    $ret[0] = ''; // default for relpath is empty string
    if(count($pi)==1) { // exploding on separator only returned one thing
      $ret[1] = $pi[0]; // the filename
      return $ret;
    } else if(count($pi)>1) { // more than one piece
      $this->logerr('more than one item in path explosion', 2);
      $ret[1] = array_pop($pi); // last one should always be filename
      $this->logerr('filename is '.$ret[1], 2);
      $parts = array();
      foreach($pi as $part) { // iterate on what's left of path steps
        if($part != null && $part != "") { // check to make sure it's valid
          $parts[] = $part; //filter to store only valid steps in path
        }
      }
      if(count($parts)>1) { // if we have more than one, join with sep
        $ret[0] = implode("/", $parts);
      } else {
        if(count($parts)==1) { // only one, just assign it
          $this->logerr('only one part left:'.$parts[0], 2);
          $ret[0] = $parts[0];
        }
      }
      $this->logerr('returning '.print_r($ret, true), 2);
      return $ret;
    } else {
      return false;
    }
    
  }
   
    private function _validID($idstr=null)
  {

    if($idstr==null) {
      $idstr = uniqid();
    }

    if(!preg_match('/^[A-Za-z]/', $idstr)) {
      $idstr = 'ID'.$idstr;   
    }

    $idstr = preg_replace('/[^A-Za-z0-9:_.-]/', '', $idstr);

    $idstr = $idstr . uniqid();
  
    return $idstr;
    
  }

  private function _isBinaryType($mime)
  {
    if(preg_match('/xml$/', $mime)) {
      return false;
    } else if (preg_match('/(png|jpeg|gif|pdf|flash|stream)$/',$mime)) { 
      return true;
    } else {
      return false;
    }
  }
   
   /** Writers - for file and archive operations */
      
   
  private function _makeDirs()
  {
        DiskUtil::assertPath($this->packagepath);
        DiskUtil::assertPath($this->metapath);
        DiskUtil::assertPath($this->opspath);
        DiskUtil::assertPath($this->opspath."/".$this->opsrel);
  }

   private function _writeFile($loc, $contents)
   {
      if(!@file_put_contents($loc, $contents, LOCK_EX)) {
         
         $me = posix_getpwuid(posix_getuid());
         $mename = $me['name'];
         $user = posix_getpwuid(fileowner($loc));
         throw new Exception("Do not have write permission for this action. Script is running as $mename but owner is ".$user['name']." and perms are ".substr(sprintf('%o', fileperms($loc)), -4));
      }
   }
   
  public function writeFile($file)
  {
    /**
    Raw writes to path in package, overwriting existing file with new content,
    or creating new file with content
    */
    
    // make sure dirs exist
    $dd = pathinfo($file['path']);
    $pi = explode('/', $dd['dirname']);   
    $fullpath = $this->packagepath;
    foreach($pi as $step) {
      $fullpath = $fullpath . '/'.$step;
      DiskUtil::makeDir($fullpath);
    }
    if(!file_put_contents($this->packagepath . '/'. $file['path'], $file['content'])) {
      $this->logerr('unable to write to '.$this->packagepath . '/'. $file['path'], 0);
    } else {
      //chmod($this->packagepath . '/'. $file['path'], 0755);
      //error_log("wrote to ".$this->packagepath . '/'. $file['path']);
    }
    
  }

  public function writeOPS()
  {
    if($this->readonly) return;
    $this->_saveMeta();
  }
  
  public function save($filename=null)
  {
    if($this->readonly) return;
    if(!$filename) throw new Exception('Save requires a filename ');
    $this->starttime = time();
    $this->_saveMeta();
    $saved = $this->_makeEpubTarget($this->packagepath.'.epub');
    DiskUtil::xRename($saved, $filename);
  }
    
  public function _saveMeta()
  {
      if($this->readonly) return;
  //  error_log('writing to '.$this->packagepath . '/'.$this->opfpath);
   // error_log('and '.$this->packagepath . '/'. $this->ncxpath);
      $this->_prepPretty();
      $this->_writeFile($this->mimetypepath, $this->getMimetypeString());
      $this->opf->save($this->packagepath . '/'.$this->opfpath);
      $this->ncx->save($this->packagepath . '/'. $this->ncxpath);
      $this->contdoc->save($this->metapath.'/container.xml');
      $this->logerr('saved',2);
      /*
      $docauthor = $this->ncx->getElementsByTagName('docAuthor')->item(0);
      
      if($docauthor) {
        try {
          $this->ncx->removeChild($docauthor);
        } catch (Exception $e) {
          error_log('caught non-fatal Exception trying to remove docAuthor node: '.$e->getMessage());  
          error_log('saved anyway. ncx is '.$this->packagepath . '/'. $this->ncxpath);
        }
      }
      */
  }
   
  public function moveOps($workpath, $opsname=null)
  {
      if($this->readonly) return;
      return $this->_moveOps($workpath, $opsname); 
   }

  public function _moveOps($workpath, $opsname=null, $savefirst=true)
  {
         if($this->readonly) return;
    // takes new workpath and new ops directory name and tries
    // to move the whole structure to the target [workpath + '/' + opsname]
    //error_log('moving ops');
    
    if($savefirst==true) {
      //error_log('saving metadata first');
      try {
        $this->_saveMeta();
      } catch (Exception $e) {
       // error_log('caught Exception: '.$e->getMessage());
      }
    }
    if($opsname==null) $opsname = uniqid(); // new uniqid for path
    
    if(!DiskUtil::xRename($this->packagepath, $workpath . '/' . $opsname)) { // fail, try to backup existing first
      $processUser = posix_getpwuid(posix_geteuid());
      error_log("failed to rename working package path ".$this->packagepath." to $workpath/$opsname. Does the web server have write permissions there? Script user is ".get_current_user()." and process owner is ".$processUser['name']);
      $bkp = $workpath . '/' . $opsname.".BACKUP".time();
      if(!DiskUtil::xRename($workpath . '/' . $opsname, $bkp)) {
        throw  new Exception('could not move ops path from '.$this->packagepath.' to '.$bkp.'!!!');       
      } else { // now try it
        if(!@DiskUtil::xRename($this->packagepath, $workpath . '/' . $opsname)) {
          throw  new Exception('could not backup and move ops path from '.$this->packagepath.' to '.$opsname.'!!!');
        }
      }
    } else {
      
      error_log('renamed OPS structure');
      
      $this->opsname = $opsname;
      $this->workpath = $workpath;
      $this->packagepath = $this->workpath . '/' . $this->opsname; // this will be the working package dir (ops)
      $this->mimetypepath = $this->packagepath . '/mimetype'; // filename of mimetype file
      $this->metapath = $this->packagepath . '/META-INF';
      $this->opspath = $this->packagepath;
    }
  }

   public function close()
   {
      
      /* only call when you're ready to destroy the object!!! */
      $junk = $this->download();
      
      
      /*
         in some operations, we open up epub files from a read-only
         source, dump their OPS structures to a temporary location,
         use that as a file store while acting on the structure,
         then remove those (sometimes after copying the file store
         to make it permanent. in these operations, we need to
         clean up after ourselves, or we will have used (2 * q * n)
         MB of disk space up.
         
      */
      // clear the package path if it's temporary
      $tmpdir = DiskUtil::getTempDir();
      error_log('tmpdir is '.$tmpdir.' and package dir is '.$this->packagepath);
      $regex = "`^".preg_quote($tmpdir)."`";
      //error_log('regex is '.$regex);
      if(preg_match($regex, $this->packagepath)) {
         error_log('closing and removing tmp cache at '.$this->packagepath);
         exec('rm -rf '.$this->packagepath);
         if(is_dir($this->packagepath)) {
            error_log('tmp package path not removed');
         }
      }
      
      
   }
   

  public function download()
  {
    /**
    
    Download whole book as epub file.
    
    */
    return $this->read();
  }
  
  public function dump()
  {
    return $this->read();
  }
    
  public function read()
  {
    /**
    
    Synonym for download. May get rolled into it.
    
    */
    try { // may not have write permissions
      $this->_saveMeta();
    } catch (Exception $e) {
      //error_log($e->getMessage());
    }
    //error_log('proceeding with epub creation');
    $arcname = $this->_makeEpubTarget();
    $ret = file_get_contents($arcname);
    unlink($arcname);
    return $ret;

  }
  
  private function _makeZipContainer($arcname)
  {
         if($this->readonly) return;
    $packagedir = $this->packagepath;
    $zip = ZIP_LOC; // path to zip command
    $zipflags = '-0 -j -X';
    $zipcmd = "$zip $zipflags $arcname $this->mimetypepath";
    if(!file_exists($this->mimetypepath)) {
      $this->logerr('***mimetype file does not exist! attempting to fix this...');
      $this->_writeFile($this->mimetypepath, $this->getMimetypeString()); 
  
      if(file_exists($this->mimetypepath)) {
        $this->logerr('success!');
      } else {
        throw new Exception('could not create a mimetype file for this ops structure');
      }
    }
    //$this->logerr('executing:'.$zipcmd);
    exec(escapeshellcmd($zipcmd), $output);
    //$this->logerr('output was:'.print_r($output, true));
    
  }
  

  private function _getZipArchive($arcname)
  {

    $zip = new ZipArchive();
    if($zip->open($arcname)!==TRUE) {
        $this->logerr("cannot open <$arcname>");      
    }
    return $zip;
    
  }
  
  private function _makeEpubTarget($arcname=null)
  {
    
     if($this->readonly) return;
    if($arcname==null) $arcname = DiskUtil::getTempDir().'/download'.uniqid(time()).'.epub';

    

    // STEP ONE:
    
    // start a zip file with only uncompressed mimetype file in it
    //$this->logerr('makeEpubTarget called for '.$arcname);

    $packagedir = $this->packagepath;
    
    $this->_makeZipContainer($arcname);
    
    
    // STEP TWO:

    // zip file according to the epub spec now has a single
    // mimetype file, uncompressed, at the start of the archive
    //
    // file is now closed and can be opened again by zip handler
    // the PHP zip handler does not allow you to specify storing
    // uncompressed files
    
    $zip = $this->_getZipArchive($arcname);
    
    // asm: the following line causes problems reading these on stanza, so leave commented
    $zip->addEmptyDir('META-INF');
    $dirnames = array();
    foreach($this->zipQ as $file=>$pathfile) {
      $pi = pathinfo($file);
      if($pi['dirname']!="." && $pi['dirname'] != "..") {
        $dirnames[$pi['dirname']] = $pi['dirname']; 
      }
    }

    $fullpath = "";
    // make sure dirs exist
    foreach($dirnames as $dirpath=>$bool) {
      $dirs = explode('/', $dirpath);
      $fullpath = "";
      foreach($dirs as $step) {
        if($fullpath=="") {
          $fullpath = $step;
        } else {
          $fullpath = $fullpath . '/'.$step;          
        }
        if(!$zip->statName($fullpath)) {
          $zip->addEmptyDir($fullpath);         
        }
      }
    }
    $zip->addFile("$packagedir/META-INF/container.xml", "META-INF/container.xml");
    $zip->addFile($packagedir . '/' . $this->opfpath, trim($this->opfpath, '/')); 
    
    //$zip->addFile($packagedir . '/' . $this->ncxpath, trim($this->ncxpath, '/'));
    
    $filenum = 3;
    foreach($this->zipQ as $file=>$pathfile) {
      $filenum++;
      $this->logerr("$filenum:$file:$pathfile");
      // with PHP, the zip extension is limited by
      // the number of filehandles allowed by the
      // system, so we have to close the zip
      // and reopen it when that limit is reached
      // see http://bugs.php.net/bug.php?id=40494
      if($filenum > $this->ziphandle_limit) {
        $zip->close();
        $zip->open($arcname);
        $filenum = 1;
      }
        
      
      //error_log('adding contents for '.$file.' from file at '.$pathfile);
      if(!file_exists($pathfile)) {
        //error_log('epub id '.$this->getPrimaryId().': file does not exist: '.$pathfile);
      }
      if(!$zip->addFile($pathfile, $file)) {
        //error_log('could not add file '.$file); 
      }
      
    }
    $zip->close();
    //error_log('done creating epub archive '.$arcname);
    return $arcname;
  }
  
  
  private function _prepPretty()
  {
      
      $this->contdoc->formatOutput = TRUE;
      $this->ncx->formatOutput = TRUE;
      $this->opf->formatOutput = TRUE;
      // no longer used
  }

  private function _modifyItem($man_id, $newcontent, $savechanges=false) {
      
  }
  
  public function suppressPurify($bool=true)
  {
    $this->suppress_purify = $bool;
  }
   
   
   
   

   
   /** Errors - logger / TODO - need exception classes */
   
    public function setLogLevel($lev)
    {
        if($lev>4) $lev = 4;
        if($lev<0) $lev = 0;
        $this->loglevel = $lev;
    }
   
  public function logerr($msg, $level=0)
  {
    if($this->logverbose && $level <= $this->loglevel) {
      //error_log($msg);
    }
  }
   
    
    public function setLogVerbose($bool)
    {
        $this->logverbose = $bool;
    }
    
    
    public function preflightReport($msg, $severity=0)
    {
       //error_log($msg);
       $this->preflight[] = array($msg, $severity);
    }

    public function getReport()
    {
       return $this->preflight;
    }

    public function getValidationReport()
    {
           if($this->readonly) return;
       if(!$this->tmpdump) {
          $this->storeAsTmpdump();
       }
       
       //error_log('executing: '.$this->epubcheck . ' ' .$this->tmpdump);
       
       exec($this->epubcheck . ' ' .$this->tmpdump.' > /dev/stdout 2>&1', $output, $result);
 
       //error_log('result:"'.$result.'"');
       
       return array($this->tmpdump, implode("\n",$output), $result);
    }
    
    public function storeAsTmpdump()
    {
           if($this->readonly) return;
       //error_log('storeAsTmpDump--saving metadata');
       $this->_saveMeta();
       //error_log('done saving to tmp');
       $this->tmpdump = $this->_makeEpubTarget(DiskUtil::getTempDir().'/_tmpepub_'.uniqid(time()).'.epub');
       //error_log('returning '.$this->tmpdump);
       return $this->tmpdump;
    }
    
    public function getTmpdumpName()
    {
       return $this->tmpdump;
    }
    
    public function removeTmpdump()
    {
       if(file_exists($this->tmpdump)) {
          return unlink($this->tmpdump);
       }
       return false;
    }
    
    
  /* BG only functions */

   
   
  public function fixNcx()
  {
    $this->_rebuildNcx();
  }


   
  public function hasBGCover() {
    foreach($this->getItemRefs() as $itemfile) {
      if(preg_match('/cover/', $itemfile['href'])) {
        //if(preg_match('/<head([^<]+?)<\/head>/m', $itemfile['content'], $matches)) {
          //echo '<head'.$matches[1].'<style></style></head>';
      //  }
        //$this->logerr('this has a new style cover');
        return $itemfile['id'];
      }
      if(preg_match('/title/', $itemfile['href'])) {
        //$this->logerr('this has an old style cover, probably no mimetype file, and linked stylesheet with Adobe DE template');
        return $itemfile['id'];
      }
    }
    return false;
  }

  public function getBGCoverData()
  {
    $ret = false;
    $refs = $this->getItemRefs();
    foreach($refs as $item) {
      if( preg_match('/cover/', $item['href']) || preg_match('/title/', $item['href']) ) {
        $ret = $this->_getItemDataUrl($item, false);
        break;
      }
    }
    return $ret;
  }

  public function getRel($href)
  {
    $rel = $this->opsrel;
    if(strlen($rel)>0) {
      $href = $rel .  '/' . $href;
    }
    return $href;
  }
  
  public function replaceBGCover($id, $cover) {
    //$this->logerr('replace bg cover '.$id);
    //$this->addHTMLItem($cover);
    $replacement = array(
      'media-type'=>'application/xhtml+xml',
      'content'=>$cover['html'],
      'fallback'=>null);
    $item = $this->replaceItemById($id, $replacement, true);
    $assets = $cover['assets'];
    $cover = null;

    $rel = $this->_getRelPathToContentRelToOpf($item->getAttribute('href'));
    
    $this->logerr('*** rel path to this file is:'.$rel, 2);
    $relpath = (strlen($rel)>0) ? "$rel/" : "";
    
    foreach($assets as $asset) {
      if($this->getItemById($asset['id'])) {
        $this->replaceItemById($asset['id'], array(
          'media-type'=>$asset['mimetype'],
          'content'=>$asset['content'],
          'fallback'=>null
          ), true);
      } else {
        $this->addItem(
          $asset['id'],
          $relpath.$asset['relpath'],
          $asset['mimetype'],
          $asset['content']
        );        
      }
    }

      $this->opf->save($this->packagepath . '/'.$this->opfpath);
      $this->ncx->save($this->packagepath . '/'. $this->ncxpath);
    //$this->logerr('saved new opf file, dumping cover data...');
  }
  
   

  private function _rebuildNcx()
  {
    
    /**
    converts ncx into mutable ordered structure
    that can be written back out to a dom later
    rebuilds ncx from structure
    */
  
  
    $N = $this->ncxXP;

    $ncw = array('docTitle'=>@$N->evaluate('//docTitle')->item(0)->textContent,
          'docAuthor'=>@$N->evaluate('//docAuthor')->item(0)->textContent,
          'navMap'=>array());
    $nm = $N->evaluate('//nc:navMap')->item(0);
    $ind = 0;

    
    foreach($this->ncx_navMapNode->getElementsByTagName('navPoint') as $np) {
      $ind++;
      $uid = ($np->getAttribute('id')) ? $np->getAttribute('id') : $this->_validID('nav');
      $class = ($np->getAttribute('class')) ? $np->getAttribute('class') : 'section';
      $playorder = $ind;
      $ncw['navMap'][] = array(
        'id'=>$uid,
        'playOrder'=>$playorder,
        'class'=>$class,
        'label'=>$np->getElementsByTagName('navLabel')->item(0)->nodeValue,
        'src'=>$np->getElementsByTagName('content')->item(0)->getAttribute('src'),
        'content'=>$np->getElementsByTagName('content')->item(0)->nodeValue
      );
    }
    //$this->logerr('processed and stored '.$ind.' navpoints from current ncx');

    $ncx = $this->ncx;
    $n = $ncx->getElementsByTagName('ncx')->item(0);
    
    $n->removeChild($this->ncx_headNode);
    $head = $n->appendChild($ncx->createElement('head'));
    $head->appendChild($this->_ncxMeta('dtb:depth', '1', $ncx));
    $head->appendChild($this->_ncxMeta('dtb:totalPageCount', '0', $ncx));
    $head->appendChild($this->_ncxMeta('dtb:maxPageNumber', '0', $ncx));
    $this->ncx_headNode = $head;
    
    $n->removeChild($this->ncx_docTitleNode);
    $dt = $n->appendChild($ncx->createElement('docTitle'));   
    $dt->appendChild($ncx->createElement('text', $ncw['docTitle']));
    $this->ncx_docTitleNode = $dt;
    
    $n->removeChild($this->ncx_docAuthorNode);
    $da = $n->appendChild($ncx->createElement('docAuthor'));
    $da->appendChild($ncx->createElement('text', $ncw['docAuthor']));
    $this->ncx_docAuthorNode = $da;
    
    $nmap = $this->ncx_navMapNode;
    
    //$this->logerr($nmap->childNodes->length . ' nodes in navmap');
    
    $nulltards = array();
    foreach($nmap->childNodes as $nc)
    {
      $nulltards[] = $nc;
    }
    foreach($nulltards as $dead)
    {
      $nmap->removeChild($dead);
    }

    foreach($ncw['navMap'] as $ncwitem) {
      $np = $ncx->createElement('navPoint');
      $np->setAttribute('id', $ncwitem['id']);
      $np->setAttribute('class', $ncwitem['class']);
      $np->setAttribute('playOrder', $ncwitem['playOrder']);
      $npel = $nmap->appendChild($np);
      $nl = $npel->appendChild($ncx->createElement('navLabel'));
      
      
      //fix labels with misencoded entities
      
      if(preg_match('/&amp;#39;/', $ncwitem['label'])) {
        $ncwitem['label'] = preg_replace('/&amp;#39;/', "'", $ncwitem['label']);
        $this->logerr('**** fixed a bad label:'.$ncwitem['label'], 2);
      }

    
         
      $nl->appendChild($ncx->createElement('text', $ncwitem['label']));
      $c = $npel->appendChild($ncx->createElement('content'));
      $c->setAttribute('src', $ncwitem['src']);
    }
    $this->ncx_navMapNode = $nmap;
         if($this->readonly) return;
      $this->ncx->save($this->packagepath . '/'. $this->ncxpath);
  }
   
   
   /* No longer used but held here for reference */
   
   
/*
  private function _tidySource($filename)
  {
    //$this->logerr('tidySource called');
    $t = new BGTidy($this->tidyloc);
    
    $t->setOpts(array('utf8', 'asxhtml', 'clean', 'numeric', 'quiet', 'file /dev/null', '-drop-proprietary-attributes false --force-output true --output-xml true --word-2000 true --doctype strict --enclose-text true --enclose-block-text true --drop-empty-paras true --drop-font-tags true'));
    
    //$t->setOpts(array('utf8', 'asxhtml', 'numeric', 'quiet', 'file /dev/null', '-drop-proprietary-attributes false --force-output true --word-2000 true --doctype strict'));
    
    //'-drop-proprietary-attributes false --force-output true --word-2000 true'
    // asm: removed 'clean' from the list of tidy options, so it preserves inline styles
    
    //$t->setOpts(array('utf8', 'asxhtml', 'quiet', 'file /dev/null', 'clean', 'numeric', '-drop-proprietary-attributes false --force-output true --word-2000 true'));
    $t->tidyFile($filename);
    //$this->logerr('tidied version is at '.$filename);
    $tid = file_get_contents($filename);
    //$this->logerr($tid);
    return $tid;
  }
*/


/*
  private function _headsToItems($heads, $basename)
  {
    // adds id to heading node for toc nav access
    $order = 0;
    foreach($heads as $head) {
      $order++;
      $myid = $head->getAttribute('id');
      if(!$myid) {
        $myid = $this->_validID('heading');
        $head->setAttribute('id', $myid);
      }
      $mylabel = preg_replace('/\n/', ' ', $head->textContent);
      $this->addNavItem($this->_validID('nav'), $mylabel, $basename.'#'.$myid, 'section');
    }
  }
   */
/*
  private function _headsToNavItems($doc, $heads, $basename)
  {
    // inserts an identified anchor before the heading
    $order = 0; $thishead = 0; $headcount = $heads->length;
    foreach($heads as $head) {
      //$this->logerr('processing head element navPoint:'.$head->textContent);
      $order++;
      $myid = $this->_validID("navPoint".$order);
      // insert an anchor point before the heading
      $a = $doc->createElement('a');
      $a->setAttribute('name', $myid);
      $a->setAttribute('id', $myid);
//      $a->setAttribute('class', 'chapter');
      if($inserted = $head->parentNode->insertBefore($a, $head)) {
        $mylabel = preg_replace('/\n/', ' ', $head->textContent);
        $this->addNavItem($this->_validID('nav'), $mylabel, $basename.'#'.$myid, 'section');        
      }
      $thishead++;
    }
  }
   */
/*
  private function _headsToNavDivs($doc, $heads, $basename)
  {
    // wraps sections in divs
    //$this->logerr('creating nav divs from head info -- basename is '.$basename);
    $order = 0; $thishead = 0; $headcount = $heads->length;
    $marker = $doc->createComment('bgdelimiter#beginContent');
    // insert before first child of body element
    $body = $doc->getElementsByTagName('body')->item(0);
    $begin = $body->insertBefore($marker, $body->firstChild);
    $marker = $doc->createComment('bgdelimiter#endContent');
    // append to end of body element
    $end = $body->appendChild($marker);
    if($heads->length > 0) {
      foreach($heads as $head) {
        //$this->logerr('processing head element navPoint:'.$head->textContent);
        $order++;
        $myid = $this->_validID("navPointDiv".$order);
        $marker = $doc->createComment('bgdelimiter#'.$myid);

        if($inserted = $head->parentNode->insertBefore($marker, $head)) {
          $mylabel = preg_replace('/\n/', ' ', $head->textContent);
          if(preg_match('/^\s$/m', $mylabel)) {
            //$this->logerr('label is empty, setting label to default');
            $mylabel = '§';
          }
          $this->addNavItem($this->_validID('nav'), $mylabel, $basename.'#'.$myid, 'section');  
        }
        $thishead++;
      }
    } else {
      $this->addNavItem($this->_validID('nav'), '§', $basename.'#beginContent', 'document');
    }
    // now we should have markers for where each section is going to begin,
    // each marker tagged with the id referenced by the navpoint link
    // bring in the wizard of tricks and his mighty staff of deception!
    $docstr = $doc->saveXML();

    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#beginContent\s?\-\->/m', '<div id="beginContent">'."\n\n", $docstr);
    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#(.+?)\s?\-\->/m', '</div>'."\n\n".'<div id="$1">'."\n\n", $docstr);
    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#endContent\s?\-\->/m', "\n\n".'</div>', $docstr);
    
    //tidy it
    $tmp = tempnam($this->workpath, 'epubwork_');
    //$this->logerr('working tempfile for marker replacement is '.$tmp);
    file_put_contents($tmp, $docstr);
    $tidied = $this->_tidySource($tmp);
    unlink($tmp);
    // reload
    $doc->loadXML($tidied);
    return $doc;
  }
  */
   /*
  private function _headsToNavDocs($doc, $headsold, $basename)
  {
    // creates new content documents based on headings
    $order = 0; $thishead = 0;
        $navpointids = array();
        $headcount = 0;
        $html = $doc->getElementsByTagName("html")->item(0);
        
        $body = $html->getElementsByTagName("body")->item(0);
        //} catch (Exception $e) {
         //   $body = $html->appendChild($doc->createElement("body"));
        //}
        $heads = $body->getElementsByTagName("h1");
        if($heads->length<1) {
            $heads = $doc->getElementsByTagName("h2");
            if($heads->length<1) {
               $heads = $doc->getElementsByTagName("h3");
                if($heads->length<1) {
                    $body->insertBefore($doc->createElement("h1","***"), $body->firstChild);
                }
            }
        }
    // calculate whether our heads ratio is good for this:
    $sectionsize = (strlen($doc->saveXML())/($headcount+1));
    $marker = $doc->createComment('bgdelimiter#beginContent');
    $begin = $body->insertBefore($marker, $body->firstChild);
    $marker = $doc->createComment('bgdelimiter#endContent');
    $end = $body->appendChild($marker);
    foreach($heads as $head) {
      $this->logerr('processing head element navPoint:'.$head->textContent);
      $order++; // basis of 1 for id strings
      $thishead++;
      $uni = uniqid();
      $myid = $this->_validID("navPointDiv$order");
      $docid = $this->_validID("content$basename"."_section$order");
      $docidfile = $basename.'-'.$order.'.html';
      $mylabel = preg_replace('/(\n|\r)/', ' ', $head->textContent);
      if(preg_match('/^\s$/m', $mylabel)) {
        $this->logerr('label is empty, setting label to default');
        $mylabel = '§';
      }
      $marker = $doc->createComment('bgdelimiter#'.$myid);
            $this->logerr("created comment");
            $ref = $head;
            while($ref->parentNode->nodeName != "body") {
                if($ref->parentNode->nodeName == "html") {
                    break;
                } else {
                    $ref = $ref->parentNode;
                }
            }
            $inserted = $ref->parentNode->insertBefore($marker, $ref);
        $navpointids[] = array('myid'=>$myid, 'docid'=>$docid, 'docidfile'=>$docidfile, 'mylabel'=>$mylabel);     
    }
    $docstr = $doc->saveXML(); // dump for regex processing
        $html->replaceChild($doc->createElement("body"),$body);
        $domtmpl = $doc; // copy remaining stuff as template
        unset($body); unset($heads); unset($doc);
    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#beginContent\s?\-\->/m', '<div id="beginContent">'."\n\n", $docstr);
    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#endContent\s?\-\->/m', "\n\n".'</div>', $docstr);
    $docstr = preg_replace('/<\!\-\-\s?bgdelimiter#(.+?)\s?\-\->/m', '</div>'."\n\n".'<div id="$1">'."\n\n", $docstr);    
    $doc = new DomDocument('1.0', 'UTF-8');
      $doc->validateOnParse = true;
    @$doc->loadXML($docstr); // load it back in
        $this->logerr('Loaded back into dom, processing new divs as separate docs');
    foreach($navpointids as $navid) {
      $myid = $navid['myid'];
      $newdoc = $domtmpl;
           $newhtmlnode = $newdoc->getElementsByTagName("html")->item(0);
           $newbodynode = $newdoc->getElementsByTagName("body")->item(0);
           $this->logerr("looking for id ".$myid);
      $navnode = $doc->getElementById($myid);
      $newbodynode->appendChild($newdoc->importNode($navnode, true)); // import the content block
      $this->addItem($navid['docid'], $navid['docidfile'], 'application/xhtml+xml', $newdoc->saveXML(), 'yes'); // add to manifest
      $this->addNavItem($this->_validID(), $navid['mylabel'], $navid['docidfile'].'#'.$myid, 'section'); // add to ncx
    }
    return true;
  }
   */
   
   /*
  public function _domFromDoc($filename, $contents=null) {
    // returns a dom from a tidied doc
    $this->logerr('domFromDoc:'.$filename, 1);
    if($contents != null) {
      $res = BookGluttonPurifier::loadFileContent($contents);
      $contents = null;
    } else {
      $res = BookGluttonPurifier::loadFile($filename);      
    }
    if($res) {
      if($this->suppress_purify) {
        $dom = BookGluttonPurifier::getDom();
      } else {
        $dom = BookGluttonPurifier::purify();
      }
    } else {
      throw new Exception('problem loading source data into dom');
    }
    return $dom;
  }
  */


}



class DiskUtil {
	public static function fileIsZip($file) 
	{
		return substr(file_get_contents($file, TRUE,null,0,3),0,2)=='PK';	
	}
    
    public static function assertPath($dir)
    { // takes full path to directory (not file)
        if(is_file
            ($dir)) {
            error_log("this is a regular file!");
           $dir = pathinfo($dir, PATHINFO_DIRNAME);
        }
        $parts = explode("/", $dir);
        $stem = "";
        while(count($parts)>0) {
            $part = array_shift($parts);
            $stem .= "$part/";
            if(!file_exists($stem)) {
                error_log("making non-existent directory:".$stem);
                mkdir($stem, 0775);
            }
        }
    }

    public static function getGroupName($file)
    {
      $oinfo = self::getGroupArray($file);
      return $oinfo['name'];      
    }
    
    
    public static function getOwnerName($file)
    {
      $oinfo = self::getOwnerArray($file);
      return $oinfo['name'];
    }
    
    public static function getGroupArray($file)
    {
      return posix_getgrgid(filegroup($file));
    }    
    
    public static function getOwnerArray($file)
    {
      return posix_getpwuid(fileowner($file));
    }    
    
	public static function makeDir($dir)
	{
		//error_log('trying to make directory:'.$dir);
		if(!file_exists($dir)) {
			// check the mode
			if(is_writable(dirname($dir))) {
				if(!mkdir($dir)) {
					throw new Exception("Could not create package directory:".$dir);	
				} else {
					chmod($dir, 0777);
				}						
			} else { // parent dir is not writeable
				if(chmod(dirname($dir),0777)) // try to chmod it
				{
					if(!mkdir($dir)) {
						throw new Exception("Could not create package directory:".$dir);	
					} else {
						chmod($dir, 0777);
					}
				} else {
					throw new Exception("Couldn't chmod dir ".$dir);
				}
			}
		} else {
			error_log("Package directory ".$dir." already exists--not going to overwrite it");
		}
	}
	
	public static function findFile($path, $regex)
	{	

		exec(escapeshellcmd("find $path -name $regex"), $output, $retval);
		if($retval==0) {
			return $output[0];
		} else {
			return false;
		}
	
	
	}
	
	public static function dir_copy($srcdir, $dstdir, $offset = '', $verbose = false)
	{
	    // A function to copy files from one directory to another one, including subdirectories and
	    // nonexisting or newer files. Function returns number of files copied.
	    // This function is PHP implementation of Windows xcopy  A:\dir1\* B:\dir2 /D /E /F /H /R /Y
	    // Syntaxis: [$returnstring =] dircopy($sourcedirectory, $destinationdirectory [, $offset] [, $verbose]);
	    // Example: $num = dircopy('A:\dir1', 'B:\dir2', 1);

	    // Original by SkyEye.  Remake by AngelKiha.
	    // Linux compatibility by marajax.
	    // Offset count added for the possibilty that it somehow miscounts your files.  This is NOT required.
	    // Remake returns an explodable string with comma differentiables, in the order of:
	    // Number copied files, Number of files which failed to copy, Total size (in bytes) of the copied files,
	    // and the files which fail to copy.  Example: 5,2,150000,\SOMEPATH\SOMEFILE.EXT|\SOMEPATH\SOMEOTHERFILE.EXT
	    // If you feel adventurous, or have an error reporting system that can log the failed copy files, they can be
	    // exploded using the | differentiable, after exploding the result string.
	    //
	    if(!isset($offset)) $offset=0;
	    $num = 0;
	    $fail = 0;
	    $sizetotal = 0;
	    $fifail = '';
			$ret = '';
	    if(!is_dir($dstdir)) mkdir($dstdir);
	    if($curdir = opendir($srcdir)) {
	        while($file = readdir($curdir)) {
	            if($file != '.' && $file != '..') {
	//                $srcfile = $srcdir . '\\' . $file;    # deleted by marajax
	//                $dstfile = $dstdir . '\\' . $file;    # deleted by marajax
	                $srcfile = $srcdir . '/' . $file;    # added by marajax
	                $dstfile = $dstdir . '/' . $file;    # added by marajax
	                if(is_file($srcfile)) {
	                    if(is_file($dstfile)) $ow = filemtime($srcfile) - filemtime($dstfile); else $ow = 1;
	                    if($ow > 0) {
	                        if($verbose) echo "Copying '$srcfile' to '$dstfile'...<br />";
	                        if(copy($srcfile, $dstfile)) {
	                            touch($dstfile, filemtime($srcfile)); $num++;
	                            chmod($dstfile, 0777);    # added by marajax
	                            $sizetotal = ($sizetotal + filesize($dstfile));
	                            if($verbose) echo "OK\n";
	                        }
	                        else {
	                            echo "Error: File '$srcfile' could not be copied to '$dstfile'!<br />\n";
	                            $fail++;
	                            $fifail = $fifail.$srcfile.'|';
	                        }
	                    }
	                }
	                else if(is_dir($srcfile)) {
	                    $res = explode(',',$ret);
	                    $ret = self::dir_copy($srcfile, $dstfile, $verbose);
	                    $mod = explode(',',$ret);
	                    @$imp = array($res[0] + $mod[0],$mod[1] + $res[1],$mod[2] + $res[2],$mod[3].$res[3]);
	                    $ret = implode(',',$imp);
	                }
	            }
	        }
	        closedir($curdir);
	    }
	    $red = explode(',',$ret);
	    @$ret = ($num + $red[0]).','.(($fail-$offset) + $red[1]).','.($sizetotal + $red[2]).','.$fifail.$red[3];
	    return $ret;
	}

	public static function getTempDir()
	{
		// Get temporary directory
		if (!empty($_ENV['TMP'])) {
		        $tempdir = $_ENV['TMP'];
		} elseif (!empty($_ENV['TMPDIR'])) {
		        $tempdir = $_ENV['TMPDIR'];
		} elseif (!empty($_ENV['TEMP'])) {
		        $tempdir = $_ENV['TEMP'];
		} else {
		        $tempdir = dirname(tempnam('', 'na'));
		}

		if (empty($tempdir)) { error_log ('No temporary directory'); }

		return $tempdir;
	}
	
	public static function xRename($src,$target)
	 {
		// bypass PHP rename by shelling to mv, which can
		// move across partitions
		$cmd = 'mv "'.$src.'" "'.$target.'"';
		$o = shell_exec($cmd);
		return true;
	 }
	
}

