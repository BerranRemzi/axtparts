-- API Tokens table for token-based authentication
-- Run this migration on an existing axtparts database to add API token support

use axtparts;

create table api_tokens (
	tokenid int unsigned auto_increment,
	token varchar(64) not null,
	token_name varchar(100),
	uid int unsigned not null,
	created_at datetime not null,
	last_used datetime,
	expires_at datetime,
	is_active tinyint(1) default 1,
	primary key (tokenid),
	unique (token),
	index (uid),
	index (is_active)
);
