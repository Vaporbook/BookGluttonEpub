BookGluttonEpub

Copyright (c) 2011, Aaron Miller

Licensed under the MIT license.

Core class for the BookGlutton publishing and social reading platform. Includes OPS virtualization, zip container manipulation, and more, in a single convenient class library. Zip container and file finder helper classes included.

DEPS

Requires epubcheck, zip, tidy, java, PHP mods for zip, dom_document, simple_xml, xpath, possibly others...

API documentation

Coming soon - There are many helpful convenience methods, and helpful comments, in the code. Please browse the main class file for more info on how to use this library.

TODO

1.Clean up the code! Production-tested but messy.
2.Add some test scripts and test epub/OPS content

Usage Examples:

Please see the test.php script for the simplest possible example of usage. More involved test scripts will be added when I get time. Do look through the main class file at some of the methods available. There is much useful there.


1. Open an epub from a file:


  $epub = new BookGluttonEpub();

  $epub->open($epub_filename);


2. Load an OPS structure into a virtualized Epub:


  $epub = new BookGluttonEpub();
  
  $epub->loadOPS($path_to_ops);


3. Open a remote epub by URL and echo its ISBN:


  $epub = new BookGluttonEpub();
  
  $epub->openRemote($href);

  $epub->setPretty(true);
    
  echo $epub->getIsbn();
  
  
4. Open an epub as a virtual zip epub and unzip its contents into an OPS structure:
  
  
  $epub = new BookGluttonZipEpub();
    
  $epub->ingestZipData($zipdata, $book->getPackagePath());
    
  print_r($epub->getMetaPairs());
  
  
  
5. Load remote, modify and save local to OPS:


  $epub = new BookGluttonEpub();

  $epub->openRemote($href);

  $epub->setTitle($book->getTitle());
  
  $epub->setAuthor($book->getAuthor());

  $epub->setDescription($book->getDescription());

  $epub->setRights($book->getRights());

  $epub->writeOPS();
  
  
6. Create a new virtual OPS, then load an HTML conversion source, then save locally as OPS:
  
  
  $epub = new BookGluttonEpub();
  
  $epub->create(array(
              'title'=>$book->getTitle(),
              'author'=>$book->getAuthor(),
              'language'=>$book->getLanguage(),
              'desc'=>$book->getDescription(),
              'rights'=>$book->getRights()
      
    ));

  $epub->loadSource($zipped_html_or_html);

  $epub->moveOps($ops_repo_root, $unique_package_directory_id);
  

  
  
  