<?php

namespace Elgentos;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use N98\Magento\Command\Indexer\AbstractIndexerCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class DevEventsFireCommand extends AbstractIndexerCommand
{
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var string[]
     *
     * Retrieved from the Magento codebase by running:
     *
     * ag "eventManager->dispatch\('" vendor/magento | awk -F 'dispatch\(' '{print $2}' | cut -d "'" -f2 | sort
     */
    private static $staticEvents = [
        'abstract_search_result_load_after',
        'abstract_search_result_load_before',
        'adminhtml_block_html_before',
        'adminhtml_block_html_before',
        'adminhtml_block_salesrule_actions_prepareform',
        'adminhtml_cache_flush_all',
        'adminhtml_cache_flush_all',
        'adminhtml_cache_flush_system',
        'adminhtml_cache_flush_system',
        'adminhtml_cache_flush_system',
        'adminhtml_cache_refresh_type',
        'adminhtml_cache_refresh_type',
        'adminhtml_catalog_category_tree_is_moveable',
        'adminhtml_catalog_product_edit_element_types',
        'adminhtml_catalog_product_edit_element_types',
        'adminhtml_catalog_product_edit_prepare_form',
        'adminhtml_catalog_product_grid_prepare_massaction',
        'adminhtml_cmspage_on_delete',
        'adminhtml_product_attribute_types',
        'adminhtml_product_attribute_types',
        'adminhtml_sales_order_create_process_data',
        'adminhtml_sales_order_create_process_data_before',
        'adminhtml_sales_order_create_process_item_after',
        'adminhtml_sales_order_create_process_item_before',
        'adminhtml_store_edit_form_prepare_form',
        'catalog_block_product_status_display',
        'catalog_category_flat_loadnodes_before',
        'catalog_category_tree_init_inactive_category_ids',
        'catalog_category_tree_init_inactive_category_ids',
        'catalog_controller_category_delete',
        'catalog_controller_product_view',
        'catalog_controller_product_view',
        'catalog_prepare_price_select',
        'catalog_product_collection_load_after',
        'catalog_product_compare_add_product',
        'catalog_product_compare_item_collection_clear',
        'catalog_product_edit_action',
        'catalog_product_gallery_prepare_layout',
        'catalog_product_get_final_price',
        'catalog_product_get_final_price',
        'catalog_product_import_finish_before',
        'catalog_product_is_salable_before',
        'catalog_product_new_action',
        'catalog_product_option_price_configuration_after',
        'catalog_product_option_price_configuration_after',
        'catalog_product_to_website_change',
        'catalog_product_view_config',
        'catalogsearch_reset_search_result',
        'catalogsearch_reset_search_result',
        'category_move',
        'checkout_cart_save_after',
        'checkout_cart_save_before',
        'checkout_multishipping_refund_all',
        'checkout_quote_destroy',
        'checkout_quote_init',
        'checkout_submit_all_after',
        'checkout_submit_all_after',
        'checkout_submit_before',
        'checkout_type_multishipping_set_shipping_items',
        'clean_cache_after_reindex',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_cache_by_tags',
        'clean_catalog_images_cache_after',
        'clean_media_cache_after',
        'clean_static_files_cache_after',
        'controller_action_layout_render_before',
        'controller_action_noroute',
        'controller_action_postdispatch',
        'controller_action_predispatch',
        'controller_front_send_response_before',
        'core_app_init_current_store_after',
        'core_collection_abstract_load_after',
        'core_collection_abstract_load_before',
        'core_layout_block_create_after',
        'cron_job_run',
        'customer_address_format',
        'customer_address_format',
        'customer_data_object_login',
        'customer_data_object_login',
        'customer_data_object_login',
        'customer_login',
        'customer_login',
        'customer_login',
        'customer_logout',
        'customer_session_init',
        'custom_quote_process',
        'default',
        'depersonalize_clear_session',
        'eav_collection_abstract_load_before',
        'gift_options_prepare',
        'gift_options_prepare_items',
        'items_additional_data',
        'layout_render_before',
        'layout_render_before_',
        'load_customer_quote_before',
        'maintenance_mode_changed',
        'model_delete_after',
        'model_delete_before',
        'model_delete_commit_after',
        'model_load_after',
        'model_load_before',
        'model_save_after',
        'model_save_before',
        'model_save_commit_after',
        'multishipping_checkout_controller_success_action',
        'on_view_report',
        'order_cancel_after',
        'payment_cart_collect_items_and_amounts',
        'payment_form_block_to_html_before',
        'permissions_role_html_before',
        'product_attribute_form_build',
        'product_attribute_form_build_front_tab',
        'product_attribute_form_build_main_tab',
        'product_attribute_grid_build',
        'product_option_renderer_init',
        'rating_rating_collection_load_before',
        'restore_quote',
        'review_controller_product_init',
        'review_controller_product_init_before',
        'review_review_collection_load_before',
        'rss_catalog_category_xml_callback',
        'rss_catalog_new_xml_callback',
        'rss_catalog_review_collection_select',
        'rss_catalog_special_xml_callback',
        'rss_order_new_collection_select',
        'rss_wishlist_xml_callback',
        'sales_convert_order_to_quote',
        'sales_order_creditmemo_refund',
        'sales_order_invoice_cancel',
        'sales_order_invoice_pay',
        'sales_order_item_cancel',
        'sales_order_payment_cancel',
        'sales_order_payment_pay',
        'sales_order_payment_place_end',
        'sales_order_payment_place_start',
        'sales_order_payment_void',
        'sales_order_place_after',
        'sales_order_place_before',
        'sales_quote_add_item',
        'sales_quote_address_discount_item',
        'sales_quote_address_discount_item',
        'sales_quote_item_qty_set_after',
        'sales_quote_product_add_after',
        'sales_quote_remove_item',
        'salesrule_rule_condition_combine',
        'salesrule_rule_get_coupon_types',
        'sales_sale_collection_query_before',
        'session_abstract_add_message',
        'session_abstract_clear_messages',
        'store_add',
        'store_add',
        'store_address_format',
        'tax_rate_data_fetch',
        'tax_settings_change_after',
        'tax_settings_change_after',
        'tax_settings_change_after',
        'tax_settings_change_after',
        'tax_settings_change_after',
        'theme_save_after',
        'view_block_abstract_to_html_before',
        'view_message_block_render_grouped_html_after',
        'visitor_activity_save',
        'visitor_init',
        'wishlist_add_item',
        'wishlist_items_renewed',
        'wishlist_product_add_after',
        'wishlist_share',
    ];
    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var EventManagerInterface
     */
    private $eventManager;

    public function inject(ObjectManager $objectManager, EventManagerInterface $eventManager)
    {
        $this->eventManager = $eventManager;
        $this->objectManager = $objectManager;
    }

    protected function configure()
    {
        $this
            ->setName('dev:events:fire')
            ->setDescription('Fire an event through Magento\'s event/observer system [elgentos]')
            ->addOption('eventName', 'e', InputOption::VALUE_REQUIRED, 'Which event do you want to run?', null)
            ->addOption('parameters', 'p', InputOption::VALUE_REQUIRED, 'Do you want to add parameters?', null);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return 1;
        }

        $questionHelper = $this->getHelper('question');

        // Get event name
        $eventName = $input->getOption('eventName');
        if (!$eventName) {
            $eventName = $questionHelper->ask(
                $input,
                $output,
                new ChoiceQuestion('<question>Select eventName to fire</question>', self::$staticEvents)
            );

            if ($eventName === 'Other....') {
                $eventName = $questionHelper->ask($input, $output, new Question('Which eventName do you want to fire?', null));
            }
        }

        if (!$eventName) {
            $output->writeln('<error>No event name given!</error>');
            return 1;
        }

        $parameters = [];
        $parameterString = $input->getOption('parameters');
        if ($parameterString) {
            $parameterStringParts = explode(';', $parameterString);
            foreach ($parameterStringParts as $parameterStringPart) {
                list($name, $value) = explode('::', $parameterStringPart);
                $parameters[$name] = $value;
            }
        } else {
            $parameterQuestion = new ConfirmationQuestion('<info>Do you want to add a parameter? [N/y]</info> ', false);
            while ($questionHelper->ask($input, $output, $parameterQuestion)) {
                $parameterName = $questionHelper->ask($input, $output, new Question('Parameter name: ', null));
                $parameterValue = $questionHelper->ask($input, $output, new Question('Parameter value: ', null));
                $parameters[$parameterName] = $parameterValue;
                $parameterQuestion = new ConfirmationQuestion('Do you want to add another parameter?  [N/y] ', false);
            }
        }

        // Populate parameters with models
        if (count($parameters)) {
            foreach ($parameters as $name => $value) {
                if (!(strpos($value, ':') === false)) {
                    list($model, $id) = explode(':', $value);
                    $objectModel = $this->objectManager->get($model);
                    if ($objectModel) {
                        $object = $objectModel->load($id);
                        if ($object->getId()) {
                            $parameters[$name] = $object;
                        }
                    }
                }
            }
        }

        // Fire event!
        try {
            $this->eventManager->dispatch($eventName, $parameters);
            if (count($parameters)) {
                $output->writeln('<info>Event ' . $eventName . ' has been fired with parameters; </info>');
                foreach ($parameters as $key => $value) {
                    if (!is_object($value)) {
                        $output->writeln('<info> - ' . $key . ': ' . $value . '</info>');
                    } else {
                        $output->writeln('<info> - object ' . $key . ': ' . get_class($value) . ' ID ' . $value->getId() . '</info>');
                    }
                }
            } else {
                $output->writeln('<info>Event ' . $eventName . ' has been fired</info>');
            }
        } catch (Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        return 0;
    }
}
