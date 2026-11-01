import React, {
    createContext,
    useCallback,
    useContext,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    Background,
    BaseEdge,
    Controls,
    EdgeLabelRenderer,
    Handle,
    MarkerType,
    MiniMap,
    Position,
    ReactFlow,
    ReactFlowProvider,
    applyEdgeChanges,
    applyNodeChanges,
    getBezierPath,
    getSmoothStepPath,
    useReactFlow,
    useUpdateNodeInternals,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './flow-canvas.css';

const START_NODE_ID = '__start__';
const CanvasContext = createContext(null);
let edgeSequence = 0;

const NODE_COLORS = {
    message: { background: '#E3F2FD', border: '#2196F3', text: '#1976D2' },
    question: { background: '#FFF3E0', border: '#FF9800', text: '#F57C00' },
    output: { background: '#E0F2F1', border: '#009688', text: '#00695C' },
    click: { background: '#FCE4EC', border: '#E91E63', text: '#C2185B' },
    branch: { background: '#F3E5F5', border: '#9C27B0', text: '#7B1FA2' },
    action: { background: '#E8F5E9', border: '#4CAF50', text: '#388E3C' },
    ai_chat: { background: '#E8EAF6', border: '#3F51B5', text: '#283593' },
    loop: { background: '#FBE9E7', border: '#FF5722', text: '#BF360C' },
    webhook: { background: '#FFE4E6', border: '#E11D48', text: '#9F1239' },
};

function t(message) {
    const messages = window.dialogawpAdmin && window.dialogawpAdmin.i18n;
    return messages && Object.prototype.hasOwnProperty.call(messages, message)
        ? messages[message]
        : message;
}

function clone(value) {
    if (typeof structuredClone === 'function') {
        return structuredClone(value);
    }
    return JSON.parse(JSON.stringify(value));
}

function portableNodes(nodes) {
    return (Array.isArray(nodes) ? nodes : []).filter(node => node && node.id !== START_NODE_ID).map(node => ({
        ...clone(node),
        type: node.type || 'custom',
        position: {
            x: Number(node.position && node.position.x) || 0,
            y: Number(node.position && node.position.y) || 0,
        },
    }));
}

function portableEdges(edges) {
    return (Array.isArray(edges) ? edges : []).filter(Boolean).map(edge => clone(edge));
}

function toCanvasNodes(nodes) {
    return [
        {
            id: START_NODE_ID,
            type: 'dialogawpStart',
            position: { x: 220, y: 28 },
            data: {},
            draggable: false,
            selectable: false,
            deletable: false,
        },
        ...nodes.map(node => ({ ...clone(node), type: 'dialogawpNode' })),
    ];
}

function toCanvasEdges(edges, nodes) {
    const nodeTypes = new Map(nodes.map(node => [node.id, node.data && node.data.nodeType]));
    return edges.map(edge => ({
        ...clone(edge),
        type: nodeTypes.get(edge.source) === 'loop' ? 'dialogawpLoop' : 'dialogawpEdge',
        markerEnd: {
            type: MarkerType.ArrowClosed,
            color: nodeTypes.get(edge.source) === 'loop' ? '#FF5722' : '#00ADA1',
        },
    }));
}

function nodeTypeForDisplay(node) {
    const type = node.data && node.data.nodeType;
    if (type === 'question' && node.data.auto_continue === true && (!node.data.options || node.data.options.length === 0)) {
        return 'message';
    }
    return type || 'message';
}

function nodeTypeLabel(type) {
    const labels = {
        message: 'Message',
        question: 'Message / Question',
        output: 'Output',
        click: 'Click',
        branch: 'Branch',
        action: 'Action',
        ai_chat: 'AI Chat',
        loop: 'Loop',
        webhook: 'Webhook',
    };
    return t(labels[type] || 'Node');
}

function nodeLabel(node, nodes, edges) {
    const data = node.data || {};
    const type = data.nodeType;
    if (type === 'loop') {
        const edge = edges.find(candidate => candidate.source === node.id);
        const target = edge && nodes.find(candidate => candidate.id === edge.target);
        const targetLabel = target && (target.data.message || target.data.label || target.data.nodeType || target.id);
        return '↩ ' + (targetLabel ? String(targetLabel).substring(0, 30) : t('(drag a wire to set target)'));
    }
    if (type === 'ai_chat') return t('AI Chat');
    if (type === 'branch') {
        const groups = data.output_groups || [];
        const label = groups.map(group => (group.conditions || []).map(condition => condition.value)
            .join(' ' + (group.logic === 'or' ? t('OR') : t('AND')) + ' ')).join(' | ');
        return label || t('Branch Logic');
    }
    if (type === 'action') {
        const config = data.config || {};
        switch (data.action_type) {
            case 'capture_lead': return t('Capture Lead');
            case 'redirect':
                try {
                    return config.url ? t('Redirect to %s').replace('%s', new URL(config.url).hostname) : t('Redirect (URL needed)');
                } catch (error) {
                    return t('Redirect (URL needed)');
                }
            case 'link_button': return config.button_label || t('Link Button (label needed)');
            case 'send_email': return t('Email: %s').replace('%s', config.to || t('Not configured'));
            case 'set_variable': return t('Set %s').replace('%s', config.variable_name || t('variable'));
            case 'tag_lead': return t('Tag: %s').replace('%s', (config.tags || []).join(', ') || t('No tags'));
            default: return t('Action (choose type)');
        }
    }
    if (type === 'webhook' && data.label === '⚡ Webhook') return '⚡ ' + t('Webhook');
    return data.label || data.message || data.question || data.output_message || t('Untitled');
}

function DialogaWPNode({ id, data, selected }) {
    const canvas = useContext(CanvasContext);
    const updateNodeInternals = useUpdateNodeInternals();
    const node = canvas.nodes.find(candidate => candidate.id === id) || { id, data };
    const type = nodeTypeForDisplay(node);
    const colors = NODE_COLORS[type] || NODE_COLORS.message;
    const groups = data.output_groups || [];
    const groupHandles = groups.map(group => group.id).join('|');

    useLayoutEffect(() => {
        updateNodeInternals(id);
    }, [groupHandles, id, updateNodeInternals]);

    const removeNode = event => {
        event.stopPropagation();
        if (window.dialogawpBuilder && window.dialogawpBuilder.deleteNode) {
            window.dialogawpBuilder.selectedNode = clone(node);
            window.dialogawpBuilder.deleteNode();
        }
    };

    return (
        <div
            className={'dialogawp-rf-node' + (selected ? ' is-selected' : '')}
            style={{ '--node-background': colors.background, '--node-border': colors.border, '--node-text': colors.text }}
            data-node-id={id}
        >
            <Handle type="target" position={Position.Top} title={t('Input')} />
            <button className="dialogawp-node-delete nodrag nopan" type="button" onClick={removeNode} title={t('Delete node')}>×</button>
            <div className="dialogawp-rf-node-type">{nodeTypeLabel(type)}</div>
            <div className="dialogawp-rf-node-label">{nodeLabel(node, canvas.nodes, canvas.edges)}</div>
            {type === 'branch' && groups.length > 0 ? groups.map((group, index) => (
                <Handle
                    key={group.id}
                    id={String(group.id)}
                    type="source"
                    position={Position.Bottom}
                    style={{ left: `${((index + 1) / (groups.length + 1)) * 100}%` }}
                    title={(group.conditions || []).map(condition => condition.value).join(' ') || t('Output %s').replace('%s', index + 1)}
                />
            )) : null}
            {type === 'loop' ? <>
                <Handle id="left" type="source" position={Position.Left} title={t('Connect from left')} />
                <Handle id="right" type="source" position={Position.Right} title={t('Connect from right')} />
            </> : null}
            {type !== 'loop' && !(type === 'branch' && groups.length > 0) ? (
                <Handle type="source" position={Position.Bottom} title={t('Click to start connection')} />
            ) : null}
        </div>
    );
}

function StartNode() {
    return (
        <div className="dialogawp-rf-start" data-start-node="true">
            <span>▶ {t('Chat starts here')}</span>
            <Handle type="source" position={Position.Bottom} data-start-handle="true" title={t('Click to connect to first node')} />
        </div>
    );
}

function DialogaWPEdge(props) {
    const canvas = useContext(CanvasContext);
    const [hovered, setHovered] = useState(false);
    const hoverTimer = useRef(null);
    const pathResult = props.data && props.data.loop
        ? getBezierPath({
            sourceX: props.sourceX,
            sourceY: props.sourceY,
            sourcePosition: props.sourcePosition,
            targetX: props.targetX,
            targetY: props.targetY,
            targetPosition: props.targetPosition,
            curvature: 0.75,
        })
        : getSmoothStepPath(props);
    const [path, labelX, labelY] = pathResult;
    const loop = Boolean(props.data && props.data.loop);
    const stroke = hovered ? '#D32F2F' : (loop ? '#FF5722' : '#00ADA1');

    return <>
        <g onMouseEnter={() => {
            window.clearTimeout(hoverTimer.current);
            setHovered(true);
        }} onMouseLeave={() => {
            hoverTimer.current = window.setTimeout(() => setHovered(false), 200);
        }} onClick={event => {
            event.stopPropagation();
            canvas.confirmDeleteEdge(props.id);
        }}>
            <BaseEdge
                id={props.id}
                path={path}
                markerEnd={props.markerEnd}
                interactionWidth={20}
                style={{ stroke, strokeWidth: hovered ? 3 : (loop ? 2.5 : 2), strokeDasharray: loop ? '8 4' : undefined }}
            />
        </g>
        {hovered ? (
            <EdgeLabelRenderer>
                <button
                    type="button"
                    className="dialogawp-rf-edge-delete nodrag nopan"
                    style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
                    onMouseEnter={() => {
                        window.clearTimeout(hoverTimer.current);
                        setHovered(true);
                    }}
                    onMouseLeave={() => setHovered(false)}
                    onClick={event => {
                        event.stopPropagation();
                        canvas.confirmDeleteEdge(props.id);
                    }}
                    title={t('Delete Connection')}
                >×</button>
            </EdgeLabelRenderer>
        ) : null}
    </>;
}

function CanvasInner(props) {
    const initialPortableNodes = portableNodes(props.initialNodes);
    const initialPortableEdges = portableEdges(props.initialEdges);
    const nodesRef = useRef(initialPortableNodes);
    const edgesRef = useRef(initialPortableEdges);
    const viewportRef = useRef({ x: 0, y: 0, zoom: 1 });
    const [nodes, setNodes] = useState(() => toCanvasNodes(initialPortableNodes));
    const [edges, setEdges] = useState(() => toCanvasEdges(initialPortableEdges, initialPortableNodes));
    const { screenToFlowPosition, fitView } = useReactFlow();

    const notifyChanged = useCallback(() => {
        if (window.jQuery) {
            window.jQuery(document).trigger('dialogawp:canvas-changed');
        }
    }, []);

    const refreshSelectedEditor = useCallback((source, target) => {
        const builder = window.dialogawpBuilder;
        if (!builder || !builder.selectedNode || !builder.selectNode) return;
        const selected = builder.selectedNode;
        if ((selected.id === target && selected.data.nodeType === 'branch') ||
            (selected.id === source && selected.data.nodeType === 'loop')) {
            const current = nodesRef.current.find(node => node.id === selected.id) || selected;
            builder.selectNode(clone(current));
        }
    }, []);

    const removeEdge = useCallback(edgeId => {
        edgesRef.current = edgesRef.current.filter(edge => edge.id !== edgeId);
        setEdges(toCanvasEdges(edgesRef.current, nodesRef.current));
        notifyChanged();
    }, [notifyChanged]);

    const confirmDeleteEdge = useCallback(edgeId => {
        const remove = () => removeEdge(edgeId);
        if (window.dialogawpModal && window.dialogawpModal.confirm) {
            window.dialogawpModal.confirm(t('Delete this connection?'), remove, {
                title: t('Delete Connection'),
                okText: t('Delete'),
                danger: true,
            });
        } else if (window.confirm(t('Delete this connection?'))) {
            remove();
        }
    }, [removeEdge]);

    const onNodesChange = useCallback(changes => {
        setNodes(current => {
            const next = applyNodeChanges(changes, current);
            const positions = new Map(next.filter(node => node.id !== START_NODE_ID).map(node => [node.id, node.position]));
            nodesRef.current = nodesRef.current.map(node => positions.has(node.id)
                ? { ...node, position: { ...positions.get(node.id) } }
                : node);
            return next;
        });
    }, []);

    const onEdgesChange = useCallback(changes => {
        setEdges(current => applyEdgeChanges(changes, current));
    }, []);

    const onConnect = useCallback(connection => {
        if (!connection.source || !connection.target || connection.source === connection.target) return;
        const edge = {
            id: `edge-${Date.now()}-${++edgeSequence}`,
            source: connection.source,
            sourceHandle: connection.sourceHandle || null,
            target: connection.target,
            type: 'smoothstep',
            animated: true,
        };
        if (connection.targetHandle) edge.targetHandle = connection.targetHandle;
        edgesRef.current = [...edgesRef.current, edge];
        setEdges(toCanvasEdges(edgesRef.current, nodesRef.current));
        notifyChanged();
        refreshSelectedEditor(edge.source, edge.target);
    }, [notifyChanged, refreshSelectedEditor]);

    const setFlow = useCallback(flow => {
        if (flow && Object.prototype.hasOwnProperty.call(flow, 'nodes') && Array.isArray(flow.nodes)) {
            nodesRef.current = portableNodes(flow.nodes);
        }
        if (flow && Object.prototype.hasOwnProperty.call(flow, 'edges') && Array.isArray(flow.edges)) {
            edgesRef.current = portableEdges(flow.edges);
        }
        setNodes(toCanvasNodes(nodesRef.current));
        setEdges(toCanvasEdges(edgesRef.current, nodesRef.current));
        // The initial fit runs before AJAX supplies an existing flow. Refit after
        // React commits the new nodes so imported and saved graphs open centered.
        requestAnimationFrame(() => requestAnimationFrame(() => fitView({ padding: 0.2, maxZoom: 1, duration: 0 })));
    }, [fitView]);

    useLayoutEffect(() => {
        const api = {
            getFlow: () => ({ nodes: clone(nodesRef.current), edges: clone(edgesRef.current) }),
            setFlow,
            getState: () => ({
                zoom: viewportRef.current.zoom,
                panOffset: { x: viewportRef.current.x, y: viewportRef.current.y },
            }),
        };
        window.dialogawpReactInstance = api;
        return () => {
            if (window.dialogawpReactInstance === api) delete window.dialogawpReactInstance;
        };
    }, [setFlow]);

    const onDrop = useCallback(event => {
        event.preventDefault();
        const nodeType = event.dataTransfer.getData('dialogawp/node-type');
        if (!nodeType || !window.dialogawpBuilder || !window.dialogawpBuilder.addNodeAtPosition) return;
        const position = screenToFlowPosition({ x: event.clientX, y: event.clientY });
        window.dialogawpBuilder.addNodeAtPosition(nodeType, position.x, position.y, false);
    }, [screenToFlowPosition]);

    const context = useMemo(() => ({
        nodes: nodesRef.current,
        edges: edgesRef.current,
        confirmDeleteEdge,
    }), [nodes, edges, confirmDeleteEdge]);

    const edgeTypes = useMemo(() => ({
        dialogawpEdge: DialogaWPEdge,
        dialogawpLoop: DialogaWPEdge,
    }), []);
    const nodeTypes = useMemo(() => ({
        dialogawpNode: DialogaWPNode,
        dialogawpStart: StartNode,
    }), []);

    return (
        <CanvasContext.Provider value={context}>
            <div className="dialogawp-rf-canvas" onDrop={onDrop} onDragOver={event => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'copy';
            }}>
                <ReactFlow
                    nodes={nodes}
                    edges={edges.map(edge => ({ ...edge, data: { ...(edge.data || {}), loop: edge.type === 'dialogawpLoop' } }))}
                    nodeTypes={nodeTypes}
                    edgeTypes={edgeTypes}
                    onNodesChange={onNodesChange}
                    onEdgesChange={onEdgesChange}
                    onConnect={onConnect}
                    isValidConnection={connection => connection.source !== connection.target}
                    onNodeClick={(event, node) => {
                        if (node.id === START_NODE_ID || !props.onNodeClick) return;
                        const portable = nodesRef.current.find(candidate => candidate.id === node.id);
                        if (portable) props.onNodeClick(clone(portable));
                    }}
                    onNodeDoubleClick={(event, node) => {
                        if (node.id === START_NODE_ID || !props.onNodeClick) return;
                        const portable = nodesRef.current.find(candidate => candidate.id === node.id);
                        if (portable) props.onNodeClick(clone(portable));
                    }}
                    onNodeDragStop={() => notifyChanged()}
                    onMove={(event, viewport) => { viewportRef.current = { ...viewport }; }}
                    fitView
                    fitViewOptions={{ padding: 0.2, maxZoom: 1 }}
                    minZoom={0.1}
                    maxZoom={3}
                    zoomOnScroll={false}
                    zoomOnPinch
                    panOnScroll
                    panOnDrag
                    zoomOnDoubleClick={false}
                    deleteKeyCode={null}
                    selectionKeyCode={null}
                    multiSelectionKeyCode={null}
                    nodesConnectable
                    nodesDraggable
                    elementsSelectable
                    proOptions={{ hideAttribution: false }}
                >
                    <Background variant="dots" gap={20} size={1} color="#E5E7EF" />
                    <Controls showInteractive={false} />
                    <MiniMap
                        pannable
                        zoomable
                        nodeColor={node => node.id === START_NODE_ID
                            ? '#16A34A'
                            : (NODE_COLORS[nodeTypeForDisplay(node)] || NODE_COLORS.message).border}
                    />
                </ReactFlow>
                <div className="dialogawp-rf-count">{(nodesRef.current.length === 1 ? t('%s node') : t('%s nodes')).replace('%s', nodesRef.current.length)}</div>
                {nodesRef.current.length === 0 ? (
                    <div className="dialogawp-rf-empty">
                        <img src={(window.dialogawpAdmin && window.dialogawpAdmin.logoUrl) || ''} alt="DialogaWP" />
                        <div>{t('Drag nodes from palette above or click buttons to add')}</div>
                    </div>
                ) : null}
            </div>
        </CanvasContext.Provider>
    );
}

function FlowCanvas(props) {
    return (
        <ReactFlowProvider>
            <CanvasInner {...props} />
        </ReactFlowProvider>
    );
}

window.dialogawpCanvasComponent = FlowCanvas;
