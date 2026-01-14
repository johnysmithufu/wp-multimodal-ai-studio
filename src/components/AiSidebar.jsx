import { useState } from '@wordpress/element';
import {
    PanelBody,
    TextareaControl,
    SelectControl,
    Button,
    Spinner,
    ToggleControl,
    TabPanel
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createBlock } from '@wordpress/blocks';
import { marked } from 'marked';
import { MediaUpload } from '@wordpress/block-editor';

export default function AiSidebar() {
    return (
        <div className="mp-ai-sidebar-content">
            <TabPanel
                className="mp-ai-tabs"
                activeClass="is-active"
                onSelect={ ( tabName ) => {} }
                tabs={ [
                    {
                        name: 'text',
                        title: 'Text / Code',
                        className: 'mp-ai-tab-text',
                    },
                    {
                        name: 'image',
                        title: 'Image Gen',
                        className: 'mp-ai-tab-image',
                    },
                ] }
            >
                { ( tab ) => (
                    tab.name === 'text' ? <TextMode /> : <ImageMode />
                ) }
            </TabPanel>
        </div>
    );
}

function TextMode() {
    const [ prompt, setPrompt ] = useState( '' );
    const [ model, setModel ] = useState( 'gemini-1.5-flash' );
    const [ isGenerating, setIsGenerating ] = useState( false );
    const [ streamBuffer, setStreamBuffer ] = useState( '' );
    const [ useMarkdown, setUseMarkdown ] = useState( true );

    // Context Options
    const [ useWebSearch, setUseWebSearch ] = useState( false );
    const [ refUrl, setRefUrl ] = useState( '' );
    const [ contextImageId, setContextImageId ] = useState( 0 );

    const { insertBlocks } = useDispatch( 'core/block-editor' );

    const handleGenerate = async () => {
        if ( ! prompt ) return;

        setIsGenerating( true );
        setStreamBuffer( '' );

        try {
            const response = await fetch( `${mpAiSettings.root}/generate-stream`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': mpAiSettings.nonce
                },
                body: JSON.stringify({
                    prompt,
                    model,
                    use_web_search: useWebSearch,
                    ref_url: refUrl,
                    context_image_id: contextImageId
                })
            });

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            while ( true ) {
                const { done, value } = await reader.read();
                if ( done ) break;

                const chunk = decoder.decode( value, { stream: true } );
                const lines = chunk.split( '\n\n' );

                for ( const line of lines ) {
                    if ( line.startsWith( 'data: ' ) ) {
                        const jsonStr = line.replace( 'data: ', '' ).trim();
                        if ( jsonStr === '[DONE]' ) break;

                        try {
                            const data = JSON.parse( jsonStr );
                            if ( data.text ) {
                                setStreamBuffer( prev => prev + data.text );
                            }
                        } catch (e) {
                            // console.error("Parse error on chunk", e);
                        }
                    }
                }
            }

        } catch ( err ) {
            setStreamBuffer( `Error: ${err.message}` );
        } finally {
            setIsGenerating( false );
        }
    };

    const handleInsert = () => {
        if ( ! streamBuffer ) return;

        if ( useMarkdown ) {
            const htmlContent = marked.parse( streamBuffer );
            const block = createBlock( 'core/html', { content: htmlContent } );
            insertBlocks( block );
        } else {
            const block = createBlock( 'core/paragraph', { content: streamBuffer } );
            insertBlocks( block );
        }
        setStreamBuffer('');
    };

    return (
        <PanelBody title="Text Generation" initialOpen={ true }>
            <SelectControl
                label="AI Model"
                value={ model }
                options={ mpAiSettings.user_models.filter( m => !m.value.includes('dall-e') && !m.value.includes('imagen') ) }
                onChange={ setModel }
            />

            <TextareaControl
                label="Prompt"
                help="Enter instructions..."
                value={ prompt }
                onChange={ setPrompt }
                rows={ 6 }
            />

            <div style={{ marginBottom: '15px' }}>
                <ToggleControl
                    label="Enable Web Search"
                    checked={ useWebSearch }
                    onChange={ setUseWebSearch }
                />
                <TextareaControl
                    label="Reference URL (Scrape)"
                    value={ refUrl }
                    onChange={ setRefUrl }
                    rows={1}
                />

                <div style={{ marginTop: '10px' }}>
                     <label className="components-base-control__label">Image Context</label>
                     <MediaUpload
                        onSelect={ ( media ) => setContextImageId( media.id ) }
                        allowedTypes={ ['image'] }
                        value={ contextImageId }
                        render={ ( { open } ) => (
                            <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                                <Button variant="secondary" onClick={ open }>
                                    { contextImageId ? 'Change Image' : 'Select Image' }
                                </Button>
                                { contextImageId !== 0 && (
                                    <Button isDestructive isLink onClick={ () => setContextImageId(0) }>
                                        Remove
                                    </Button>
                                )}
                            </div>
                        ) }
                    />
                    { contextImageId !== 0 && (
                        <div style={{ marginTop: '5px', fontSize: '11px', color: '#666' }}>ID: {contextImageId} selected</div>
                    )}
                </div>
            </div>

            <Button
                variant="primary"
                onClick={ handleGenerate }
                disabled={ isGenerating || !prompt }
                isBusy={ isGenerating }
            >
                Generate Text
            </Button>

            { ( isGenerating || streamBuffer ) && (
                <div style={{ marginTop: '15px' }}>
                    <h3>Preview</h3>
                    <div className="mp-ai-preview-box" style={{
                        background: '#f0f0f1',
                        padding: '10px',
                        borderRadius: '4px',
                        fontSize: '13px',
                        minHeight: '100px',
                        maxHeight: '300px',
                        overflowY: 'auto',
                        whiteSpace: 'pre-wrap'
                    }}>
                        { streamBuffer }
                        { isGenerating && <Spinner /> }
                    </div>

                    <div style={{ marginTop: '10px' }}>
                        <ToggleControl
                            label="Parse Markdown"
                            checked={ useMarkdown }
                            onChange={ setUseMarkdown }
                        />
                        <Button variant="secondary" onClick={ handleInsert } disabled={ isGenerating }>
                            Insert into Editor
                        </Button>
                    </div>
                </div>
            ) }
        </PanelBody>
    );
}

function ImageMode() {
    const [ prompt, setPrompt ] = useState( '' );
    const [ model, setModel ] = useState( 'dall-e-3' );
    const [ isGenerating, setIsGenerating ] = useState( false );
    const [ result, setResult ] = useState( null );
    const [ error, setError ] = useState( null );

    const { insertBlocks } = useDispatch( 'core/block-editor' );

    const handleGenerate = async () => {
        if ( ! prompt ) return;
        setIsGenerating( true );
        setResult( null );
        setError( null );

        try {
            const response = await fetch( `${mpAiSettings.root}/generate-image`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': mpAiSettings.nonce
                },
                body: JSON.stringify({ prompt, model })
            });

            const data = await response.json();

            if ( ! response.ok ) throw new Error( data.message || data.code );

            setResult( data );

        } catch ( err ) {
            setError( err.message );
        } finally {
            setIsGenerating( false );
        }
    };

    const handleInsert = () => {
        if ( ! result ) return;

        const block = createBlock( 'core/image', {
            url: result.url,
            alt: result.alt,
            id: result.media_id
        } );
        insertBlocks( block );
    };

    return (
        <PanelBody title="Image Generation" initialOpen={ true }>
            <SelectControl
                label="Image Model"
                value={ model }
                options={ mpAiSettings.user_models.filter( m => m.value.includes('dall-e') || m.value.includes('imagen') ) }
                onChange={ setModel }
            />

            <TextareaControl
                label="Image Description"
                help="Describe the image you want to create."
                value={ prompt }
                onChange={ setPrompt }
                rows={ 4 }
            />

            <Button
                variant="primary"
                onClick={ handleGenerate }
                disabled={ isGenerating || !prompt }
                isBusy={ isGenerating }
            >
                Generate Image
            </Button>

            { error && <div style={{ color: 'red', marginTop: '10px' }}>Error: { error }</div> }

            { result && (
                <div className="mp-ai-image-preview">
                    <img src={ result.url } alt={ result.alt } />
                    <Button variant="secondary" onClick={ handleInsert } style={{ marginTop: '10px' }}>
                        Insert Image
                    </Button>
                </div>
            ) }
        </PanelBody>
    );
}
