--
-- Table to keep track of namespaces and their configuration
--
CREATE TABLE /*_*/namespace_mgr (
	   -- The ID of the namespace
	   ns_id int,

	   -- The name of the namespace
	   ns_name varbinary(255) not null,

	   -- The constant used in code for this NS
	   ns_constant varbinary(255) not null,

	   -- Whether this namespace is changeable using this extension.
	   ns_read_only boolean not null,

	   -- If this namespace is includable
	   ns_includable boolean not null,

	   -- Whether lockdown is used on this namespace
	   ns_lockdown boolean not null,

	   -- Whether subpages are allowed
	   ns_subpages boolean not null,

	   -- Can Collection be used on this?
	   ns_collection boolean not null,

	   -- Is Flow used for discussion pages?
	   ns_flow boolean not null,

	   -- Is PageTriage usable here?
	   ns_pagetriage boolean not null,

	   -- Is SMW usable here?
	   ns_smw boolean not null,

	   -- Is VE usable here?
	   ns_ve boolean not null,

	   -- Are user functions ok here?
	   ns_userfunctions boolean not null,

	   -- Permission needed
	   ns_permission varbinary(255),

	   -- Group this namespace is restricted to
	   ns_group varbinary(255),

	   -- The creator/owner of the namespace
	   ns_owner int unsigned not null,

	   -- A JSON config of the namespace
	   ns_config blob
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/ns_id ON /*_*/namespace_mgr (ns_id);
CREATE UNIQUE INDEX /*i*/ns_name ON /*_*/namespace_mgr (ns_name);
