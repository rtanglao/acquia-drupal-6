/* $Id: README.txt,v 1.1.2.1.2.11 2009/02/20 15:20:38 pwolanin Exp $ */

This module integrates Drupal with the Apache Solr search platform. Solr search
can be used as a replacement for core content search and boasts both extra
features and better performance. Among the extra features is the ability to have
faceted search on facets ranging from content author to taxonomy to arbitrary
CCK fields.

The module comes with a schema.xml and solrconfig.xml file which should be used
in your Solr installation.

This module depends on the search framework in core. However, you may not want
the core searches and only want Solr search. If that is the case, you want to
use the Core Searches module in tandem with this module.


Installation
------------

Prerequisite: Java 5 or higher (a.k.a. 1.5.x).  PHP 5.2.0 or higher.

Install the Apache Solr Drupal module as you would any Drupal module.

Before enabling it, you must also do the following:

Get the PHP library from the external project. The project is
found at:  http://code.google.com/p/solr-php-client/
From the apachesolr module directory, run this command:

svn checkout -r5 http://solr-php-client.googlecode.com/svn/trunk/ SolrPhpClient

Note that revision 5 is the currently tested and suggested revision. If you
do not have svn, you may be able to downlaod a copy of the library from
https://issues.apache.org/jira/browse/SOLR-341

Download Solr trunk (candidate 1.4.x build) from a nightly build or build it
from svn.  http://people.apache.org/builds/lucene/solr/nightly/

Once Solr 1.4 is released, you will be able to download from:
http://www.apache.org/dyn/closer.cgi/lucene/solr/

Unpack the tarball somewhere not visible to the web (not in your apache docroot
and not inside of your drupal directory).

The Solr download comes with an example application that you can use for
testing, development, and even for smaller production sites. This
application is found at apache-solr-nightly/example.

Move apache-solr-nightly/example/solr/conf/schema.xml and rename it to
something like schema.bak. Then move the schema.xml that comes with the
ApacheSolr Drupal module to take its place.

Similarly, move apache-solr-nightly/example/solr/conf/solrconfig.xml and rename
it like solrconfig.bak. Then move the solrconfig.xml that comes with the
ApacheSolr Drupal module to take its place.

Now start the solr application by opening a shell, changing directory to
apache-solr-nightly/example, and executing the command java -jar start.jar

Test that your solr server is now available by visiting
http://localhost:8983/solr/admin/

Now, you should  enable the "Apache Solr framework" and "Apache Solr search" 
modules. Check that you can connect to Solr at ?q=admin/setting/apachesolr
Now run cron on your Drupal site until your content is indexed. You
can monitor the index at ?q=admin/settings/apachesolr/index

The solrconfig.xml that comes with this modules defines auto-commit, so
it may take a few minutes between running cron and when the new content
is visible in search.

Enable blocks for facets first at Administer > Site configuration > Apache Solr > Enabled filters,
then position them as you like at Administer > Site building > Blocks.   

Troubleshooting
--------------
Problem:
Links to nodes appear in the search results with a different host name or
subdomain than is preferred.  e.g. sometimes at http://example.com
and sometimes at http://www.example.com

Solution:
Set $base_url in settings.php to insure that an identical absolute url is
generated at all times when nodes are indexed.  Alternately, set up a re-direct
in .htaccess to prevent site visitors from accessing the site via more than one
site address.


Developers
--------------

Exposed Hooks:

@param &$document Apache_Solr_Document
@param $node StdClass
hook_apachesolr_update_index(&$document, $node)

This hook is called just before indexing the document.
It allows you to add fields to the $document object which is sent to Solr.
For reference on the $document object, see:
SolrPhpClient/Apache/Solr/Document.php
