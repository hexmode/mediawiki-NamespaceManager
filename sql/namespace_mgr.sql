--
-- Table to keep track of namespaces and their configuration
--
CREATE TABLE /*_*/namespace_mgr (
	   -- The ID of the namespace
	   ns_id int,

	   -- The name of the namespace
	   ns_name binary(30) not null,

	   -- The creator/owner of the namespace
	   ns_owner binary(30) not null,

	   -- Whether this namespace is changeable using this extension.
	   ns_read_only boolean,

	   -- A JSON config of the namespace
	   ns_config blob
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/ns_id ON /*_*/namespace_mgr (ns_id);
CREATE UNIQUE INDEX /*i*/ns_name ON /*_*/namespace_mgr (ns_name);
