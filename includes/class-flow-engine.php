<?php
/**
 * Flow Engine for DIALOGAWP
 * Processes conversation flows and determines next steps
 */

if (!defined('ABSPATH')) {
    exit;
}

class DialogaWP_Engine {
    
    /**
     * Process a user response and get next node
     */
    public static function process_response($flow_data, $current_node_id, $user_response) {
        $nodes = isset($flow_data['nodes']) ? $flow_data['nodes'] : array();
        $edges = isset($flow_data['edges']) ? $flow_data['edges'] : array();
        
        // Find current node
        $current_node = null;
        foreach ($nodes as $node) {
            if ($node['id'] === $current_node_id) {
                $current_node = $node;
                break;
            }
        }
        
        if (!$current_node) {
            return null;
        }
        
        // Determine next node based on node type
        $node_type = isset($current_node['data']['nodeType']) ? $current_node['data']['nodeType'] : 'question';
        
        switch ($node_type) {
            case 'question':
                // Check if auto-continue (no user input needed)
                if (isset($current_node['data']['auto_continue']) && $current_node['data']['auto_continue']) {
                    return self::get_next_node($edges, $current_node_id);
                }
                
                // Check if user clicked a button or typed text
                return self::handle_question_node($current_node, $edges, $user_response, $nodes);
                
            case 'branch':
                // Branch nodes use conditional logic
                return self::handle_branch_node($current_node, $edges, $user_response);
                
            case 'action':
                // Action nodes perform actions then continue
                return self::get_next_node($edges, $current_node_id);
                
            default:
                return self::get_next_node($edges, $current_node_id);
        }
    }
    
    /**
     * Get next node from edges
     */
    public static function get_next_node($edges, $current_node_id, $source_handle = null) {
        foreach ($edges as $edge) {
            if ($edge['source'] === $current_node_id) {
                // If source_handle specified, match it
                if ($source_handle !== null) {
                    if (isset($edge['sourceHandle']) && $edge['sourceHandle'] === $source_handle) {
                        return $edge['target'];
                    }
                } else {
                    // No handle specified, return first edge
                    return $edge['target'];
                }
            }
        }
        return null;
    }
    
    /**
     * Handle question node - match user response to options or text
     */
    private static function handle_question_node($node, $edges, $user_response, $nodes) {
        $options = isset($node['data']['options']) ? $node['data']['options'] : array();
        
        // First, check if user response matches any button option exactly
        foreach ($options as $option) {
            if (strtolower(trim($user_response)) === strtolower(trim($option['label']))) {
                // User clicked or typed this option - follow to next node
                return self::get_next_node($edges, $node['id']);
            }
        }
        
        // If text input allowed and user typed something, go to next node
        // The next node might be a Branch that evaluates the text
        if (isset($node['data']['allow_text_input']) && $node['data']['allow_text_input']) {
            return self::get_next_node($edges, $node['id']);
        }
        
        // Default: go to next node
        return self::get_next_node($edges, $node['id']);
    }
    
    /**
     * Handle branch node conditional logic
     */
    private static function handle_branch_node($node, $edges, $user_response) {
        dialogawp_debug_log('Branch node: evaluating conditions against user response.');
        
        $output_groups = isset($node['data']['output_groups']) ? $node['data']['output_groups'] : array();
        
        dialogawp_debug_log('  output groups: ' . count($output_groups));
        
        // Evaluate each output group
        foreach ($output_groups as $group) {
            dialogawp_debug_log('  checking group ' . $group['id']);
            $conditions = isset($group['conditions']) ? $group['conditions'] : array();
            $logic = isset($group['logic']) ? $group['logic'] : 'and';
            
            dialogawp_debug_log('    logic ' . $logic . ', ' . count($conditions) . ' condition(s)');
            
            $results = array();
            foreach ($conditions as $condition) {
                $result = self::evaluate_condition($condition, $user_response);
                dialogawp_debug_log('    condition ' . $condition['operator'] . ' => ' . ($result ? 'TRUE' : 'FALSE'));
                $results[] = $result;
            }
            
            // Apply logic (AND/OR)
            $group_match = false;
            if ($logic === 'and') {
                $group_match = !in_array(false, $results); // All must be true
            } else {
                $group_match = in_array(true, $results); // Any can be true
            }
            
            dialogawp_debug_log('    group result: ' . ($group_match ? 'MATCHED' : 'NO MATCH'));
            
            if ($group_match) {
                // This group matched - find edge with this sourceHandle
                $group_id = $group['id'];
                dialogawp_debug_log('  group matched, using sourceHandle ' . $group_id);
                return self::get_next_node($edges, $node['id'], $group_id);
            }
        }
        
        dialogawp_debug_log('  no groups matched, trying default edge');
        
        // No groups matched - try default edge (first one with no handle)
        foreach ($edges as $edge) {
            if ($edge['source'] === $node['id'] && !isset($edge['sourceHandle'])) {
                dialogawp_debug_log('  using default edge to ' . $edge['target']);
                return $edge['target'];
            }
        }
        
        dialogawp_debug_log('  no default edge found');
        return null;
    }
    
    /**
     * Evaluate a condition
     */
    private static function evaluate_condition($condition, $value) {
        $operator = isset($condition['operator']) ? $condition['operator'] : 'equals';
        $compare_value = isset($condition['value']) ? $condition['value'] : '';
        
        switch ($operator) {
            case 'equals':
                return strtolower(trim($value)) === strtolower(trim($compare_value));
            case 'contains':
                return stripos($value, $compare_value) !== false;
            case 'starts_with':
                return stripos($value, $compare_value) === 0;
            case 'not_empty':
                return !empty($value);
            default:
                return false;
        }
    }
    
    /**
     * Execute action node
     */
    public static function execute_action($node, $session_data) {
        $action_type = isset($node['data']['action_type']) ? $node['data']['action_type'] : '';
        $config = isset($node['data']['config']) ? $node['data']['config'] : array();
        
        switch ($action_type) {
            case 'capture_lead':
                self::capture_lead($session_data);
                break;
            case 'send_email':
                self::send_notification_email($config);
                break;
            case 'tag_lead':
                // Tags are collected and applied in the frontend AJAX handler
                break;
            case 'redirect':
                // Redirect is handled on frontend
                break;
        }
    }
    
    /**
     * Capture lead data
     */
    private static function capture_lead($session_data) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'dialogawp_leads';
        
        // TODO: Collect actual lead data from session
        $wpdb->insert(
            $leads_table,
            array(
                'conversation_id' => null,
                'lead_data' => json_encode($session_data),
                'status' => 'new',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Send notification email
     */
    private static function send_notification_email($config) {
        $to = isset($config['email_to']) ? $config['email_to'] : get_option('admin_email');
        $subject = isset($config['email_subject']) ? $config['email_subject'] : 'New DIALOGAWP Lead';
        $message = isset($config['email_message']) ? $config['email_message'] : 'A new lead was captured.';
        
        wp_mail($to, $subject, $message);
    }
    
    /**
     * Get start node from flow
     */
    public static function get_start_node($flow_data) {
        $nodes = isset($flow_data['nodes']) ? $flow_data['nodes'] : array();
        
        // Look for node with is_start = true
        foreach ($nodes as $node) {
            if (isset($node['data']['is_start']) && $node['data']['is_start']) {
                return $node['id'];
            }
        }
        
        // Return first node if no start node marked
        return !empty($nodes) ? $nodes[0]['id'] : null;
    }
}
