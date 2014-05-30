<?php
if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Google Sitemap Lite
 *
 * @package		Google Sitemap Lite
 * @category	Modules
 * @author		Rein de Vries <info@reinos.nl>
 * @link        http://reinos.nl/add-ons/google-sitemap-lite
 * @copyright 	Copyright (c) 2013 Reinos.nl Internet Media
 */

/**
 * Load the navee module
 */
if(file_exists(PATH_THIRD.'navee/mod.navee.php'))
{
	require_once PATH_THIRD.'navee/mod.navee.php';
}


$plugin_info = array(
	'pi_name'        => 'Google Sitemap Lite',
	'pi_version'     => '1.11',
	'pi_author'      => 'Rein de vries',
	'pi_author_url'  => 'https://github.com/reinos/ee_google_sitemap_lite',
	'pi_description' => 'Generate Google Sitemap based on the Structure/Taxonomy/Navee module',
	'pi_usage'       => Google_sitemap_lite::usage()
  );


class Google_sitemap_lite
{

	private $EE; 
	private $site_map 					= array();
	private $site_id;
	private $site_url;
	private $site_index;
	public $return_data;
	private $taxonomy_version   		= '';
	private $structure_version  		= '';
	private $navee_version      		= '';

	private $changefreq = '';
	private $changefreq_listing = '';
	private $prio = '';
	private $prio_listing = '';
	private $prio_homepage = '';
	
	private $exclude = '';
	
	/**
	 * Constructor
	 * 
	 * @return unknown_type
	 */
	public function __construct()
	{	
		$this->EE =& get_instance();	
		$this->site_id = $this->EE->TMPL->fetch_param('site_id') == '' ? $this->EE->config->item('site_id') : $this->EE->TMPL->fetch_param('site_id');  
		$this->site_url = $this->EE->config->item('site_url');		
		$this->site_index = $this->EE->config->item('site_index');
		$this->use_hide_from_nav = $this->EE->TMPL->fetch_param('use_hide_from_nav') == "yes" ? true : false;

		$this->changefreq = $this->EE->TMPL->fetch_param('changefreq', 'weekly');
		$this->changefreq_listing = $this->EE->TMPL->fetch_param('changefreq_listing', 'daily');
		$this->prio = $this->EE->TMPL->fetch_param('prio', '0.8');
		$this->prio_listing = $this->EE->TMPL->fetch_param('prio_listing', '0.5');
		$this->prio_homepage = $this->EE->TMPL->fetch_param('prio_homepage', '1');

		//get the Taxonomy version
		$results = $this->EE->db->query("SELECT module_version FROM exp_modules WHERE module_name LIKE '%Taxonomy%' LIMIT 0,1");
		$this->taxonomy_version = $results->row('module_version');

		//get the Structure version
		$results = $this->EE->db->query("SELECT module_version FROM exp_modules WHERE module_name LIKE '%Structure%' LIMIT 0,1");
		$this->structure_version = $results->row('module_version');	

		//get the Navee version
		$results = $this->EE->db->query("SELECT module_version FROM exp_modules WHERE module_name LIKE '%Navee%' LIMIT 0,1");
		$this->navee_version = $results->row('module_version');
		
		//exclude entries
		$this->exclude = $this->EE->TMPL->fetch_param('exclude');
	}
	
	/**
	 * Generate the sitemap
	 * 
	 * @return unknown_type
	 */
	public function generate()
	{      
		//fetch the mode
		$type = $this->EE->TMPL->fetch_param('type') != ''?strtolower($this->EE->TMPL->fetch_param('type')):'';
		
		//respect trailing slash
		$add_trailing_slash = 'n';
		$site_url = $this->_loc_escapes($this->site_url);
		if($type == 'structure')
		{
			// Get the setting from strucure add_trailing_slash
			// http://devot-ee.com/add-ons/support/google-sitemap-lite/viewthread/8703
			$q = $this->EE->db->select('var_value')
				->from('structure_settings')
				->where('var', 'add_trailing_slash')
				->get();
			if($q->num_rows() > 0)
			{
				$result = $q->row();
				$add_trailing_slash = $result->var_value;
				if($add_trailing_slash == 'n') {
					$site_url = rtrim($site_url, '/');
				}
			}
		}

		//add the root item
		$this->site_map[] = array(
			'loc' => $this->_loc_escapes($site_url),
			'lastmod' => date('Y-m-d'),
			'changefreq' => $this->changefreq,
			'priority' => $this->prio_homepage
		);

		//structure sitemap
		if($type == 'structure')
		{
			if($this->structure_version)
			{
				$this->_structure();
			}
		}
		
		//navee sitemap
		else if($type == 'navee')
		{
			if($this->navee_version)
			{
				$this->navee = new Navee();
				$this->_navee();
			}
		}
		
		//Taxonomy sitemap
		else if($type == 'taxonomy')
		{
			if($this->taxonomy_version)
			{
				$this->_taxonomy();
			}
		}
		
		//format the xml and print it to the screen
		$format = '<?xml version="1.0" encoding="utf-8"?>';
		$format .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		if(!empty($this->site_map))
		{
			$format .= $this->_format_sitemap($this->site_map);
		}
		$format .= '</urlset>';
		return $format;
		
	}
	
	/************************************************************************************************
     * Taxonomy functions
     ************************************************************************************************/
	function _taxonomy()
	{
		$this->EE->load->add_package_path(PATH_THIRD.'taxonomy/');
		
		//fetch the mode
		$nav_id = $this->EE->TMPL->fetch_param('nav_id') != ''?strtolower($this->EE->TMPL->fetch_param('nav_id')):'';
      
		//check if this nav_id is in range with the site_id
		$query = $this->EE->db->get_where('taxonomy_trees', array(
			'site_id' => $this->site_id
		));
		
		if($query->num_rows() > 0)
		{
	  
			//taxonomy v2.x
			if(substr($this->taxonomy_version,0,1) == '2')
			{
				$this->EE->load->library('Ttree');
			}
			
			//taxonomy v1.2.3
			else
			{
				$this->EE->load->library('MPTtree');
				
				//set the options    
				$this->EE->mpttree->set_opts(array( 
					'left' => 'lft',
					'right' => 'rgt',
					'id' => 'node_id',
					'title' => 'label'
				));
			}

			//taxonomy v2.x     
			if(substr($this->taxonomy_version,0,1) == '2')
			{
				//fetch single nav id
				if(!empty($nav_id))
				{
					//set the table
					$this->EE->ttree->set_table($nav_id);

					//process the nav array
					$this->_taxonomy_sitemap($this->EE->ttree->tree_to_array(1));  
				}
				
				//when there is no nav id, fetch all navigations
				else
				{
					//get al nav id`s
					$query = $this->EE->db->get_where('taxonomy_trees', array(
						'site_id' => $this->site_id
					));
					
					if($query->num_rows() > 0 )
					{
						foreach($query->result() as $result)
						{
							//set the table
							$this->EE->ttree->set_table($result->id);

							//process the nav array
							$this->_taxonomy_sitemap($this->EE->ttree->tree_to_array(1));  
						}
					}
				}    
			}
			
			//taxonomy v1.2.3
			else
			{
				//fetch single nav id
				if(!empty($nav_id))
				{
					//set the table
					$this->EE->mpttree->set_table('exp_taxonomy_tree_'.$nav_id);
										
					//process the nav array
					$this->_taxonomy_sitemap($this->EE->mpttree->tree2array_v2(1));
				}
				
				//when there is no nav id, fetch all navigations
				else
				{
					//get al nav id`s
					$query = $this->EE->db->get_where('taxonomy_trees', array(
						'site_id' => $this->site_id
					));
					
					if($query->num_rows() > 0 )
					{
						foreach($query->result() as $result)
						{
							//set the table
							$this->EE->mpttree->set_table('exp_taxonomy_tree_'.$result->id);
												
							//process the nav array
							$this->_taxonomy_sitemap($this->EE->mpttree->tree2array_v2(1));
						}
					}
				}
				
			}    
		}
	}
	
	/**
     * Create the nav 
     * 
     * @param $nav
     * @return unknown_type
     */  
    function _taxonomy_sitemap($nav){
          
       if(!empty($nav))
       {  
	        foreach ($nav as $data){
	        	
	        	//format the url	
	           	$url = $this->_format_url($data);
	           
	           	//format array
				$this->site_map[] = array(
					'loc' => $this->_loc_escapes($url),
					'lastmod' => date('Y-m-d'),
					'changefreq' => $this->changefreq,
					'priority' => $this->prio
				);
				
	            //has children
	            if(!empty($data['children'])) {
	               $this->_taxonomy_sitemap($data['children']);
	            }        
	        } 
       }      
    }
    
    /**
     * Format the urls for only taxonomy
     * 
     * @param $data
     * @return unknown_type
     */
    function _format_url($data) {  
            
        //build the segments
        $template_group =   $data['group_name'];
        $template_name =    '/'.$data['template_name']; 
        $url_title =        '/'.$data['url_title'];
        
        //when the index is set, add a forslash
        if(!empty($this->site_index)) {
            $template_group =   '/'.$template_group; 
        }
        
        // don't display /index
        if($template_name == '/index')
        {
            $template_name = '';
        }
        
        //build the url
        $node_url =     $this->EE->functions->fetch_site_index().$template_group.$template_name.$url_title;
                
        // override template and entry slug with custom url if set
        if($data['custom_url'] != '')
        {
            $node_url = $data['custom_url'];
        }
        
        return $node_url;
    }
	
	/************************************************************************************************
     * Navee functions
     ************************************************************************************************/
	function _navee()
	{
		//fetch the mode
		$nav_id = $this->EE->TMPL->fetch_param('nav_id') != ''?strtolower($this->EE->TMPL->fetch_param('nav_id')):'';
		
		//fetch single nav id
		if(!empty($nav_id))
		{
			//generate the sitemap
			$this->_navee_sitemap($this->navee->_getNav($nav_id));
		}
		
		//when there is no nav id, fetch all navigations
		else
		{
			//get al nav id`s
			$query = $this->EE->db->get_where('navee_navs', array(
				'site_id' => $this->site_id
			));
			
			if($query->num_rows() > 0 )
			{
				foreach($query->result() as $result)
				{
					//generate the sitemap
					$this->_navee_sitemap($this->navee->_getNav($result->navigation_id));
				}
			}
		}
	}
	
	
	/**
	 * Structure the data
	 * 
	 * @param $data
	 * @param $label
	 * @return unknown_type
	 */
	private function _navee_sitemap($data)
	{	
		if(!empty($data))
		{
			foreach($data as $val)
			{
				//format array
				$this->site_map[] = array(
					'loc' => $this->_loc_escapes($val['link']),
					'lastmod' => date('Y-m-d'),
					'changefreq' => $this->changefreq,
					'priority' => $this->prio
				);	
				if(!empty($val['kids']))
				{
					$this->_navee_sitemap($val['kids']);
				}
			}
		}
	}
	
	/************************************************************************************************
     * Structure functions
     ************************************************************************************************/
	
	function _structure()
	{                  
		//get the wygwam hook to retrieve the pages from structure
    	if ($this->EE->extensions->active_hook('wygwam_config'))
		{
			$pages = $this->EE->extensions->call('wygwam_config', '', '');
			
			//only if the link types are set
			if(!empty($pages['link_types'])) 
			{
				//fetch the mode
				$mode = $this->EE->TMPL->fetch_param('mode') != ''?strtolower($this->EE->TMPL->fetch_param('mode')):'';
				
				//show only the pages, no listings
				if($mode == 'pages')
				{
					$this->_struc_sitemap($pages['link_types']['Structure Pages'], 'Structure Pages');
				}
				//show all
				else
				{
					foreach($pages['link_types'] as $label => $data) 
					{
						$this->_struc_sitemap($data, $label);
					}		
				}	
			}			
		}
	}
	
	/**
	 * Structure the data
	 * 
	 * @param $data
	 * @param $label
	 * @return unknown_type
	 */
	private function _struc_sitemap($data, $label)
	{
		// Listing
		if (preg_match("/Listing/i", $label)) {
			$listing = true;	
		} else {
			$listing = false;	
		}
		
		//get the links from the pages var
		$site_pages = $this->EE->config->item('site_pages');

		//get the database result for hide_from_nav purpose
		$hide_from_nav = array();
		if($this->use_hide_from_nav)
		{		
			$query = $this->EE->db->get('structure');
			if ($query->num_rows() > 0)
			{
				 foreach ($query->result() as $row)
				 {
				 	if($row->hidden == 'y')
				 	{
				 		$hide_from_nav[] = $row->entry_id;
				 	}
				 }
			}
		}
		
		//are there any site pages for this site?
		if(isset($site_pages[$this->site_id]['uris']))
		{
			$page_uris = $site_pages[$this->site_id]['uris'];

			// Get the setting from strucure add_trailing_slash
			// http://devot-ee.com/add-ons/support/google-sitemap-lite/viewthread/8703
			$q = $this->EE->db->select('var_value')
				->from('structure_settings')
				->where('var', 'add_trailing_slash')
				->get();
			if($q->num_rows() > 0)
			{
				$result = $q->row();
				$add_trailing_slash = $result->var_value;
			}
			else
			{
				$add_trailing_slash = 'n';
			}
			
			//
			if(!empty($data))
			{
				foreach($data as $val)
				{
					//Get page and listing Entry ids
					$uri_segment = str_replace(array($this->site_url, $this->site_index), '', $val['url']);
					//is there a need for a extra segment?
					if(!$entry_id = array_search($uri_segment, $page_uris))
					{
						$entry_id = array_search('/'.$uri_segment, $page_uris);

						//fix for finding a match http://devot-ee.com/add-ons/support/google-sitemap-lite/viewthread/7539#27765
						if($entry_id == '')
						{
							$entry_id = array_search($uri_segment.'/', $page_uris);
						}

						if($entry_id == '')
						{
							$entry_id = array_search('/'.$uri_segment.'/', $page_uris);
						}
					}

					//check on the hidden pages
					if(($this->use_hide_from_nav && in_array($entry_id, $hide_from_nav)) || (in_array($entry_id, explode('|', $this->exclude))))
					{
						continue;	
					}
					
					//fetch segments
					$segments = $this->_clean_up_array(explode('/', $val['url']));
					
					//get the last modified data
					$result = $this->EE->db->get_where('channel_titles', array('entry_id' => $entry_id))->row();
					if(!empty($date->edit_date))
					{
						$date = date('Y-m-d',$this->EE->localize->timestamp_to_gmt($date->edit_date));
					}
					else
					{
						$date = date('Y-m-d');
					}
					
					//format array
					$this->site_map[] = array(
						'loc' => $this->_loc_escapes($val['url']).($add_trailing_slash == 'y' ? '/' : ''),
						'lastmod' => $date,
						'changefreq' => $listing ? $this->changefreq_listing : $this->changefreq,
						'priority' => $listing ? $this->prio_listing : $this->prio
					);
				}
			}
		}
	}
  
	/************************************************************************************************
     * Utilities functions
     ************************************************************************************************/
	/**
	 * Format the xml
	 * 
	 * @return unknown_type
	 */
	private function _format_sitemap()
	{
		$format = "\n";
		
		if(!empty($this->site_map))
		{
			foreach($this->site_map as $key => $val)
			{
				//is there a link
				if(!empty($val['loc']))
				{
					//site url and index
					$site_url = $this->EE->functions->fetch_site_index();
					
					//bestaat de url al met http en www
					if (!preg_match("/www./i", $val['loc']) && !preg_match("/http/i", $val['loc'])) {
						//add a slash when he is not there
						$slash = $val['loc'][0] == '/'?'':'/'; 
						$val['loc'] = $site_url.$slash.$val['loc'];
					} 
					
					// remove double slashes
					$val['loc'] = preg_replace("#(^|[^:])//+#", "\\1/", $val['loc']);
					
					//format the xml
					$format .= "<url>\n";	
					$format .= "<loc>".$val['loc']."</loc>\n";	
					$format .= "<lastmod>".$val['lastmod']."</lastmod>\n";	
					$format .= "<changefreq>".$val['changefreq']."</changefreq>\n";	
					$format .= "<priority>".$val['priority']."</priority>\n";	
					$format .= "</url>\n";	
				}	
			}
		}
		return $format;
	}
	
	/**
	 * Remove empty alues from array
	 * 
	 * @param $array
	 * @return unknown_type
	 */
	private function _clean_up_array($array)
	{
		foreach ($array as $key => $value) {
			$value = trim($value);
			if (is_null($value) || empty($value)) {
				unset($array[$key]);
			}
		}
		return $array;
	}

	/**
	* Function added to find and replace required escape characters in line with sitemaps.org/protocol.html
	* @param type $val
	* @return type 
	*/
	private function _loc_escapes($val)
	{
		$val = str_replace('&', "&amp;", $val);
		$val = str_replace('', "&apos;", $val);
		$val = str_replace('>',"&gt;", $val);
		$val = str_replace('<', "&lt;", $val);
		$val = str_replace('"', "&quot;", $val); 

		return $val;
	}


  function usage()
  {
  ob_start(); 
  ?>

		This plugin will generate a Google XML sitemap based on the Structure module

		=============================
		The Tag
		=============================

        {exp:google_sitemap_lite:generate}

		==============
		TAG PARAMETERS
		==============
		
		(Only for "Structure")
		Include or exclude the listings
        
        mode="all [or] pages"
        	DEFAULT : all  
        -------------------

        (Only for "Structure")
		Use the hide_from_nav option
        
        use_hide_from_nav="yes [or] no"
        	DEFAULT : no  
        -------------------
          
		Which data will be converted to a XML sitemap
        
        type="taxonomy [or] structure [or] navee"
        -------------------
        
        (Only for "Taxonomy/Navee")  
        Which menu wil be converted
        
        nav_id="1"
        -------------------
		
		Which site_id from getting the information.
		Default this will be the site where you currently on
        
        site_id="1"
        -------------------
        
        Exclude some entries
        
        exclude="1|2|3"
        -------------------
          
		==============
		EXAMPLES
		==============
		{exp:google_sitemap_lite:generate type="structure" mode="all"} 
		{exp:google_sitemap_lite:generate type="structure"}
		This genereate a full sitemap for the structure pages
		
		{exp:google_sitemap_lite:generate type="structure" mode="pages"}
		This genereate a sitemap for only the pages from structure (Not the listings) 
		
		{exp:google_sitemap_lite:generate type="taxonomy" nav_id="1"}
		This genereate a full sitemap for a taxonomy nav specified by an ID 
		
		{exp:google_sitemap_lite:generate type="taxonomy"}
		This genereate a full sitemap for taxonomy navee navs

		{exp:google_sitemap_lite:generate type="navee" nav_id="1"}
		This genereate a full sitemap for a navee nav specified by an ID 	

		{exp:google_sitemap_lite:generate type="navee"}
		This genereate a full sitemap for all navee navs
			  
		  
		==============
		HOW TO
		==============
		- Create a new template called 'sitemap'
		- Set the type for this template to 'XML'
		- Place the tag in the template '{exp:google_sitemap_lite:generate type="structure"}'
		
		Now the template generate a XML sitemap bases on the structure pages.
          

		
  <?php
  $buffer = ob_get_contents();
	
  ob_end_clean(); 

  return $buffer;
  }
  // END

}
/* End of file pi.google_sitemap_lite.php */ 
/* Location: ./system/expressionengine/third_party/google_sitemap_lite/pi.google_sitemap_lite.php */ 