CREATE TABLE tx_semanticsuggestion_similarities (
    uid int(11) unsigned NOT NULL auto_increment,
    page_id int(11) unsigned DEFAULT '0' NOT NULL,
    similar_page_id int(11) unsigned DEFAULT '0' NOT NULL,
    similarity_score float DEFAULT '0' NOT NULL,
    root_page_id int(11) unsigned DEFAULT '0' NOT NULL,
    sys_language_uid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    source varchar(20) DEFAULT 'solr' NOT NULL,
    PRIMARY KEY (uid),
    KEY page_id (page_id),
    KEY root_page_id (root_page_id)
);
