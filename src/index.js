import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { tip } from '@wordpress/icons';

import AiSidebar from './components/AiSidebar';
import './index.css';

registerPlugin( 'mp-ai-content-generator', {
    icon: tip,
    render: () => (
        <>
            <PluginSidebarMoreMenuItem
                target="mp-ai-sidebar"
                icon={ tip }
            >
                { __( 'AI Content Studio', 'mp-ai-content-generator' ) }
            </PluginSidebarMoreMenuItem>

            <PluginSidebar
                name="mp-ai-sidebar"
                title={ __( 'AI Content Studio', 'mp-ai-content-generator' ) }
                icon={ tip }
            >
                <AiSidebar />
            </PluginSidebar>
        </>
    ),
} );
