/**
 * DIALOGA Visual Canvas
 * v0.28.9 - FEATURE: Delete connections by hovering and clicking
 */

(function() {
    'use strict';

    function t(message) {
        const i18n = window.dialogaAdmin && window.dialogaAdmin.i18n;
        return i18n && Object.prototype.hasOwnProperty.call(i18n, message) ? i18n[message] : message;
    }

    // Debug logging — only active when WP_DEBUG is true
    var _log = (window.dialogaAdmin && window.dialogaAdmin.debug) ? console.log.bind(console) : function() {};
    var _warn = (window.dialogaAdmin && window.dialogaAdmin.debug) ? console.warn.bind(console) : function() {};
    var _error = console.error.bind(console);

    _log('🎨 Initializing DIALOGA Canvas v0.28.9...');
    
    const { useState, useCallback, useEffect, useMemo } = React;
    const h = React.createElement;
    
    // Main Canvas Component
    function FlowCanvas(props) {
        const [nodes, setNodes] = useState(props.initialNodes || []);
        const [edges, setEdges] = useState(props.initialEdges || []);
        const [selectedNodeId, setSelectedNodeId] = useState(null);
        const [selectedEdgeId, setSelectedEdgeId] = useState(null);
        const [hoveredEdgeId, setHoveredEdgeId] = useState(null);
        const [draggedNode, setDraggedNode] = useState(null);
        const [connectingFrom, setConnectingFrom] = useState(null);
        const [connectionPreviewMouse, setConnectionPreviewMouse] = useState(null);
        const [panOffset, setPanOffset] = useState({ x: 0, y: 0 });
        const [isPanning, setIsPanning] = useState(false);
        const [panStart, setPanStart] = useState({ x: 0, y: 0 });
        const [zoom, setZoom] = useState(1);
        const [updateTrigger, setUpdateTrigger] = useState(0);
        const [isDraggingNode, setIsDraggingNode] = useState(false);
        const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 });
        const mouseDownInfoRef = React.useRef(null);
        const hasDraggedRef = React.useRef(false);

        // New approach: Use mouse events instead of HTML5 drag
        const handleNodeMouseDown = useCallback((e, node) => {
            // Only drag on left click, not on handles/buttons
            if (e.button !== 0) return;
            if (e.target.closest('.dialoga-node-delete')) return;
            if (e.target.classList.contains('dialoga-node-delete')) return;

            _log('🎯 Node mouse down:', node.id);

            const rect = e.currentTarget.getBoundingClientRect();
            const offsetX = e.clientX - rect.left;
            const offsetY = e.clientY - rect.top;

            mouseDownInfoRef.current = { node: node, startX: e.clientX, startY: e.clientY };
            hasDraggedRef.current = false;

            setDraggedNode(node);
            setIsDraggingNode(true);
            setDragOffset({ x: offsetX, y: offsetY });

            e.stopPropagation(); // Prevent canvas pan
        }, []);
        
        const handleDragOver = useCallback((e) => {
            e.preventDefault();
            e.stopPropagation();
            // Accept all drops
            e.dataTransfer.dropEffect = 'copy';
        }, []);
        
        // Handle drops from palette only (node movement now uses mouse events)
        const handleDrop = useCallback((e) => {
            e.preventDefault();
            e.stopPropagation();
            
            _log('📥 Drop event on canvas (React handler)');
            
            const paletteNodeType = e.dataTransfer.getData('dialoga/node-type');
            
            _log('🔍 Drop data:', { paletteNodeType });
            
            // ONLY handle palette drops
            if (paletteNodeType) {
                _log('🎨 Palette drop - creating node');
                
                const canvas = e.currentTarget;
                const rect = canvas.getBoundingClientRect();
                const dropX = (e.clientX - rect.left - panOffset.x) / zoom;
                const dropY = (e.clientY - rect.top - panOffset.y) / zoom;
                
                if (window.dialogaBuilder && window.dialogaBuilder.addNodeAtPosition) {
                    window.dialogaBuilder.addNodeAtPosition(paletteNodeType, dropX, dropY, false);
                }
                return;
            }
            
            _log('⚠️ Not a palette drop - ignoring');
        }, [zoom, panOffset]);
        
        const handleNodeDoubleClick = useCallback((node) => {
            _log('🖱️ Node double-clicked:', node.id);
            setSelectedNodeId(node.id);
            if (props.onNodeClick) {
                props.onNodeClick(node);
            }
        }, [props]);
        
        const handleOutputClick = useCallback((e, node, handleId = null) => {
            e.stopPropagation();
            const connectionId = handleId ? `${node.id}:${handleId}` : node.id;
            
            if (connectingFrom === connectionId) {
                _log('❌ Connection cancelled');
                setConnectingFrom(null);
            } else {
                _log('🔗 Connection start from', connectionId);
                setConnectingFrom(connectionId);
            }
        }, [connectingFrom]);
        
        const handleInputClick = useCallback((e, targetNode) => {
            e.stopPropagation();
            
            if (!connectingFrom) {
                _log('⚠️ No connection in progress');
                return;
            }
            
            if (connectingFrom === targetNode.id) {
                _log('❌ Cannot connect to self');
                setConnectingFrom(null);
                setConnectionPreviewMouse(null);
                return;
            }
            
            _log('✅ Connection complete:', connectingFrom, '→', targetNode.id);
            
            const [sourceNodeId, sourceHandleId] = connectingFrom.includes(':') 
                ? connectingFrom.split(':') 
                : [connectingFrom, null];
            
            const newEdge = {
                id: `edge-${Date.now()}`,
                source: sourceNodeId,
                sourceHandle: sourceHandleId,
                target: targetNode.id,
                type: 'smoothstep',
                animated: true
            };
            
            setEdges(prevEdges => {
                const updatedEdges = [...prevEdges, newEdge];

                setTimeout(() => {
                    if (window.dialogaBuilder && window.dialogaBuilder.selectedNode) {
                        const selectedNode = window.dialogaBuilder.selectedNode;
                        // Branch editor: refresh when branch is the target
                        if (selectedNode.id === targetNode.id && selectedNode.data.nodeType === 'branch') {
                            _log('🔄 Refreshing branch editor with new connection');
                            window.dialogaBuilder.selectNode(selectedNode);
                        }
                        // Loop editor: refresh when loop is the source (new target wire drawn)
                        if (selectedNode.id === sourceNodeId && selectedNode.data.nodeType === 'loop') {
                            _log('🔄 Refreshing loop editor with new target connection');
                            window.dialogaBuilder.selectNode(selectedNode);
                        }
                    }
                }, 50);

                return updatedEdges;
            });
            setConnectingFrom(null);
            setConnectionPreviewMouse(null);
        }, [connectingFrom]);
        
        const handleCanvasMouseDown = useCallback((e) => {
            if (e.target.id === 'dialoga-canvas' || e.target.classList.contains('canvas-background')) {
                setIsPanning(true);
                setPanStart({ x: e.clientX - panOffset.x, y: e.clientY - panOffset.y });
            }
        }, [panOffset]);
        
        const handleCanvasMouseMove = useCallback((e) => {
            if (isPanning) {
                setPanOffset({
                    x: e.clientX - panStart.x,
                    y: e.clientY - panStart.y
                });
            } else if (isDraggingNode && draggedNode) {
                // Detect real drag vs. click (3px movement threshold)
                if (!hasDraggedRef.current && mouseDownInfoRef.current) {
                    const dx = e.clientX - mouseDownInfoRef.current.startX;
                    const dy = e.clientY - mouseDownInfoRef.current.startY;
                    if (Math.sqrt(dx * dx + dy * dy) > 3) {
                        hasDraggedRef.current = true;
                    }
                }

                // Only reposition once a real drag has started
                if (hasDraggedRef.current) {
                    const canvas = document.getElementById('dialoga-canvas');
                    const rect = canvas.getBoundingClientRect();

                    const newX = (e.clientX - rect.left - panOffset.x - dragOffset.x) / zoom;
                    const newY = (e.clientY - rect.top - panOffset.y - dragOffset.y) / zoom;

                    setNodes(prevNodes =>
                        prevNodes.map(n =>
                            n.id === draggedNode.id
                                ? { ...n, position: { x: newX, y: newY } }
                                : n
                        )
                    );
                }
            } else if (connectingFrom) {
                // Connection preview - track mouse position
                const canvas = document.getElementById('dialoga-canvas');
                const rect = canvas.getBoundingClientRect();
                setConnectionPreviewMouse({
                    x: e.clientX - rect.left,
                    y: e.clientY - rect.top
                });
            }
        }, [isPanning, panStart, isDraggingNode, draggedNode, zoom, panOffset, dragOffset, connectingFrom]);
        
        const handleCanvasMouseUp = useCallback(() => {
            if (isDraggingNode) {
                // If no meaningful drag happened, treat as a single-click on the node
                if (!hasDraggedRef.current && mouseDownInfoRef.current) {
                    const clickedNode = mouseDownInfoRef.current.node;
                    _log('🖱️ Node clicked (single-click):', clickedNode.id);
                    setSelectedNodeId(clickedNode.id);
                    if (props.onNodeClick) {
                        props.onNodeClick(clickedNode);
                    }
                } else {
                    _log('✅ Node drag complete');
                }
                setIsDraggingNode(false);
                setDraggedNode(null);
                setDragOffset({ x: 0, y: 0 });
                mouseDownInfoRef.current = null;
                hasDraggedRef.current = false;
            }
            setIsPanning(false);
        }, [isDraggingNode]);
        
        // v0.40.0 — mouse-wheel zoom removed. Users were accidentally zooming
        // when they meant to scroll the WordPress admin page (bug reports:
        // "I keep zooming when I try to scroll down the flow list"). Zoom now
        // only responds to the +/- buttons in the canvas UI so it's an
        // intentional action. Wheel events fall through to the browser
        // default (scroll parent page) — no preventDefault, no onWheel prop.
        // If we ever re-add wheel-zoom, gate it behind ctrlKey/metaKey so
        // it's opt-in: `if (!e.ctrlKey && !e.metaKey) return;`
        const handleWheel = useCallback(() => {}, []);
        
        const handleCanvasClick = useCallback((e) => {
            if (e.target.id === 'dialoga-canvas' || e.target.classList.contains('canvas-background')) {
                if (connectingFrom) {
                    _log('❌ Connection cancelled (clicked canvas)');
                    setConnectingFrom(null);
                    setConnectionPreviewMouse(null);
                }
            }
        }, [connectingFrom]);
        
        const apiInstance = useMemo(() => ({
            getFlow: () => ({ nodes, edges }),
            setFlow: (flowData) => {
                if (flowData.nodes) setNodes([...flowData.nodes]);
                if (flowData.edges) setEdges([...flowData.edges]);
            },
            getState: () => ({ zoom, panOffset })
        }), [nodes, edges, zoom, panOffset]);
        
        useEffect(() => {
            window.dialogaReactInstance = apiInstance;
            _log('✅ DIALOGAReactInstance API updated');
            return () => { delete window.dialogaReactInstance; };
        }, [apiInstance]);
        
        useEffect(() => {
            if (props.initialNodes && props.initialNodes.length !== nodes.length) {
                _log('🔄 Syncing from props:', props.initialNodes.length, 'nodes');
                setNodes(props.initialNodes);
            }
        }, [props.initialNodes]);
        
        useEffect(() => {
            const timer = setTimeout(() => setUpdateTrigger(prev => prev + 1), 50);
            return () => clearTimeout(timer);
        }, [nodes, edges]);
        
        const getHandlePosition = useCallback((node, side, handleId = null) => {
            // Virtual start node — measure the actual green handle position from the DOM
            // (badge size varies with font/zoom, so DOM measurement is more reliable than estimating)
            if (node.id === '__start__') {
                const handleEl = document.querySelector('[data-start-handle="true"]');
                const canvas = document.getElementById('dialoga-canvas');

                if (handleEl && canvas) {
                    const canvasRect = canvas.getBoundingClientRect();
                    const handleRect = handleEl.getBoundingClientRect();
                    return {
                        x: handleRect.left + handleRect.width / 2 - canvasRect.left,
                        y: handleRect.top + handleRect.height / 2 - canvasRect.top
                    };
                }

                // Fallback: estimate. Badge total height ≈ padding(6) + text(11) + padding(6) + border(4) = 27
                // Plus marginTop of green dot (4) + half of dot height (6) = ~37 from top of indicator
                return side === 'bottom'
                    ? { x: 300 * zoom + panOffset.x, y: (28 + 37) * zoom + panOffset.y }
                    : { x: 300 * zoom + panOffset.x, y: 28 * zoom + panOffset.y };
            }

            const baseX = node.position.x * zoom + panOffset.x;
            const baseY = node.position.y * zoom + panOffset.y;
            
            const nodeElement = document.querySelector(`[data-node-id="${node.id}"]`);
            let actualWidth, actualHeight;
            
            if (nodeElement) {
                const rect = nodeElement.getBoundingClientRect();
                actualWidth = rect.width;
                actualHeight = rect.height;
            } else {
                const paddingX = 20 * zoom;
                const paddingY = 15 * zoom;
                const nodeContentWidth = 200 * zoom;
                actualWidth = nodeContentWidth + (paddingX * 2);
                const estimatedContentHeight = 35 * zoom;
                actualHeight = (paddingY * 2) + estimatedContentHeight;
            }
            
            // Left/right side handles — used by loop nodes (source) and loop targets (destination)
            if (side === 'left')  return { x: baseX,               y: baseY + actualHeight / 2 };
            if (side === 'right') return { x: baseX + actualWidth,  y: baseY + actualHeight / 2 };

            if (side === 'bottom') {
                if (node.data.nodeType === 'branch' && node.data.output_groups && node.data.output_groups.length > 0) {
                    if (handleId) {
                        const groupIndex = node.data.output_groups.findIndex(group => group.id === handleId);
                        if (groupIndex !== -1) {
                            const totalGroups = node.data.output_groups.length;
                            const handleX = baseX + ((groupIndex + 1) / (totalGroups + 1)) * actualWidth;
                            return { x: handleX, y: baseY + actualHeight };
                        }
                    }
                    const totalGroups = node.data.output_groups.length;
                    const handleX = baseX + (1 / (totalGroups + 1)) * actualWidth;
                    return { x: handleX, y: baseY + actualHeight };
                }
                return { x: baseX + (actualWidth / 2), y: baseY + actualHeight };
            } else {
                return { x: baseX + (actualWidth / 2), y: baseY };
            }
        }, [zoom, panOffset, updateTrigger]);
        
        const connectionLines = edges.map(edge => {
            const sourceNode = nodes.find(n => n.id === edge.source)
                || (edge.source === '__start__' ? { id: '__start__', position: { x: 300, y: 28 }, data: { nodeType: 'start' } } : null);
            const targetNode = nodes.find(n => n.id === edge.target);

            if (!sourceNode || !targetNode) return null;

            const isLoopEdge = sourceNode.data.nodeType === 'loop';

            // For loop edges, side is stored in sourceHandle ('left' or 'right')
            // Fall back to auto-detect by position for older edges without a stored side
            const estimatedNodeWidth = 240;
            const loopSide = isLoopEdge
                ? (edge.sourceHandle === 'left' || edge.sourceHandle === 'right'
                    ? edge.sourceHandle
                    : ((targetNode.position.x + estimatedNodeWidth / 2) < (sourceNode.position.x + estimatedNodeWidth / 2) ? 'left' : 'right'))
                : 'bottom';

            // Loop exits one side, swings wide, enters target from the SAME side
            const targetSide = isLoopEdge ? loopSide : 'top';
            const start = getHandlePosition(sourceNode, isLoopEdge ? loopSide : 'bottom', isLoopEdge ? null : edge.sourceHandle);
            const end   = getHandlePosition(targetNode, targetSide);

            const handleRadius = 6;
            const dx = end.x - start.x;
            const dy = end.y - start.y;
            const distance = Math.sqrt(dx * dx + dy * dy);
            const shortenFactor = distance > handleRadius ? (distance - handleRadius) / distance : 1;

            const adjustedEndX = start.x + dx * shortenFactor;
            const adjustedEndY = start.y + dy * shortenFactor;

            let path, midX, midPointY;

            if (isLoopEdge) {
                // Both control points swing out to the same side — clean arc, no crossing
                const sweep = loopSide === 'left' ? -280 : 280;
                path = `M ${start.x} ${start.y} C ${start.x + sweep} ${start.y}, ${adjustedEndX + sweep} ${adjustedEndY}, ${adjustedEndX} ${adjustedEndY}`;
                midX = start.x + sweep;
                midPointY = (start.y + adjustedEndY) / 2;
            } else {
                const midY = (start.y + adjustedEndY) / 2;
                path = `M ${start.x} ${start.y} C ${start.x} ${midY}, ${adjustedEndX} ${midY}, ${adjustedEndX} ${adjustedEndY}`;
                midX = (start.x + adjustedEndX) / 2;
                midPointY = (start.y + adjustedEndY) / 2;
            }

            const isHovered = hoveredEdgeId === edge.id;
            const strokeColor = isHovered ? '#d32f2f' : (isLoopEdge ? '#ff5722' : '#2196f3');
            const arrowMarker = isLoopEdge ? 'url(#arrowhead-loop)' : 'url(#arrowhead)';

            return h('svg', {
                key: edge.id,
                style: {
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    pointerEvents: 'none',
                    zIndex: 0
                }
            }, [
                // Invisible wider path for easier hovering/clicking
                h('path', {
                    d: path,
                    stroke: 'transparent',
                    strokeWidth: 20,
                    fill: 'none',
                    style: { pointerEvents: 'stroke', cursor: 'pointer' },
                    onMouseEnter: () => setHoveredEdgeId(edge.id),
                    onMouseLeave: () => setHoveredEdgeId(null),
                    onClick: (e) => {
                        e.stopPropagation();
                        dialogaModal.confirm(t('Delete this connection?'), function() {
                            _log('🗑️ Deleting edge:', edge.id);
                            setEdges(prevEdges => prevEdges.filter(e => e.id !== edge.id));
                        }, { title: t('Delete Connection'), okText: t('Delete'), danger: true });
                    }
                }),
                // Visible connection line
                h('path', {
                    d: path,
                    stroke: strokeColor,
                    strokeWidth: isHovered ? 3 : (isLoopEdge ? 2.5 : 2),
                    strokeDasharray: isLoopEdge ? '8,4' : 'none',
                    fill: 'none',
                    markerEnd: isHovered ? 'url(#arrowhead)' : arrowMarker,
                    style: {
                        pointerEvents: 'none',
                        transition: 'stroke 0.2s, stroke-width 0.2s'
                    }
                }),
                // Delete button on hover
                isHovered ? h('g', {
                    style: { pointerEvents: 'none' }
                }, [
                    h('circle', {
                        cx: midX,
                        cy: midPointY,
                        r: 12,
                        fill: '#d32f2f',
                        stroke: '#fff',
                        strokeWidth: 2
                    }),
                    h('text', {
                        x: midX,
                        y: midPointY,
                        textAnchor: 'middle',
                        dominantBaseline: 'middle',
                        fill: '#fff',
                        fontSize: '16px',
                        fontWeight: 'bold',
                        style: { userSelect: 'none' }
                    }, '×')
                ]) : null
            ]);
        });
        
        // Connection preview line (while dragging)
        const connectionPreviewLine = (() => {
            if (!connectingFrom || !connectionPreviewMouse) return null;
            
            const [sourceNodeId, sourceHandleId] = connectingFrom.includes(':') 
                ? connectingFrom.split(':') 
                : [connectingFrom, null];
            
            const sourceNode = nodes.find(n => n.id === sourceNodeId)
                || (sourceNodeId === '__start__' ? { id: '__start__', position: { x: 300, y: 28 }, data: { nodeType: 'start' } } : null);
            if (!sourceNode) return null;

            const start = getHandlePosition(sourceNode, 'bottom', sourceHandleId);
            const end = { x: connectionPreviewMouse.x, y: connectionPreviewMouse.y };
            
            const midY = (start.y + end.y) / 2;
            const path = `M ${start.x} ${start.y} C ${start.x} ${midY}, ${end.x} ${midY}, ${end.x} ${end.y}`;
            
            return h('svg', {
                key: 'connection-preview',
                style: {
                    position: 'absolute',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    pointerEvents: 'none',
                    zIndex: 100
                }
            }, [
                h('path', {
                    d: path,
                    stroke: '#ff9800',
                    strokeWidth: 3,
                    strokeDasharray: '8,4',
                    fill: 'none',
                    opacity: 0.7
                })
            ]);
        })();
        
        const nodeElements = nodes.map(node => {
            const colors = {
                message: { bg: '#e3f2fd', border: '#2196f3', text: '#1976d2' },
                question: { bg: '#fff3e0', border: '#ff9800', text: '#f57c00' },
                output: { bg: '#e0f2f1', border: '#009688', text: '#00695c' },
                click: { bg: '#fce4ec', border: '#e91e63', text: '#c2185b' },
                branch: { bg: '#f3e5f5', border: '#9c27b0', text: '#7b1fa2' },
                action: { bg: '#e8f5e9', border: '#4caf50', text: '#388e3c' },
                ai_chat: { bg: '#e8eaf6', border: '#3f51b5', text: '#283593' },
                loop: { bg: '#fbe9e7', border: '#ff5722', text: '#bf360c' },
                webhook: { bg: '#ffe4e6', border: '#e11d48', text: '#9f1239' }
            };
            const nodeTypeLabels = {
                message: t('Message'), question: t('Message / Question'), branch: t('Branch'),
                action: t('Action'), ai_chat: t('AI Chat'), loop: t('Loop'), webhook: t('Webhook'),
                output: t('Output'), click: t('Click')
            };

            // question nodes with auto_continue and no options are display-only messages
            const isMessageNode = node.data.nodeType === 'question' &&
                node.data.auto_continue === true &&
                (!node.data.options || node.data.options.length === 0);

            const color = colors[isMessageNode ? 'message' : node.data.nodeType] || colors.message;
            const isSelected = selectedNodeId === node.id;
            const isConnecting = connectingFrom === node.id;
            
            return h('div', {
                key: node.id,
                className: 'dialoga-node-container',
                'data-node-id': node.id,
                style: {
                    position: 'absolute',
                    left: (node.position.x * zoom + panOffset.x) + 'px',
                    top: (node.position.y * zoom + panOffset.y) + 'px',
                    padding: (15 * zoom) + 'px ' + (20 * zoom) + 'px',
                    borderRadius: (8 * zoom) + 'px',
                    border: `${2 * zoom}px solid ${color.border}`,
                    background: color.bg,
                    minWidth: (200 * zoom) + 'px',
                    cursor: isDraggingNode && draggedNode && draggedNode.id === node.id ? 'grabbing' : 'grab',
                    boxShadow: isSelected ? '0 4px 12px rgba(0,0,0,0.2)' : '0 2px 4px rgba(0,0,0,0.1)',
                    transform: `scale(${isSelected ? 1.02 : 1})`,
                    transition: isDraggingNode && draggedNode && draggedNode.id === node.id ? 'none' : 'all 0.2s',
                    zIndex: isSelected ? 10 : (isDraggingNode && draggedNode && draggedNode.id === node.id ? 100 : 1),
                    fontSize: (13 * zoom) + 'px',
                    userSelect: 'none'
                },
                onMouseDown: (e) => handleNodeMouseDown(e, node)
            }, [
                h('button', {
                    key: 'delete-btn',
                    className: 'dialoga-node-delete',
                    style: {
                        position: 'absolute',
                        top: (4 * zoom) + 'px',
                        right: (4 * zoom) + 'px',
                        width: (20 * zoom) + 'px',
                        height: (20 * zoom) + 'px',
                        borderRadius: '50%',
                        background: '#d32f2f',
                        color: 'white',
                        border: 'none',
                        cursor: 'pointer',
                        fontSize: (12 * zoom) + 'px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        opacity: 0,
                        transition: 'opacity 0.2s',
                        zIndex: 20,
                        pointerEvents: 'auto'
                    },
                    onMouseDown: (e) => e.stopPropagation(),
                    onClick: (e) => {
                        e.stopPropagation();
                        // deleteNode already has its own modal confirm
                        if (window.dialogaBuilder) {
                            window.dialogaBuilder.selectedNode = node;
                            window.dialogaBuilder.deleteNode();
                        }
                    },
                    onMouseEnter: (e) => { e.target.style.opacity = '1'; },
                    title: t('Delete node')
                }, '×'),
                
                h('div', {
                    key: 'input-handle',
                    style: {
                        position: 'absolute',
                        left: '50%',
                        top: (-8 * zoom) + 'px',
                        transform: 'translateX(-50%)',
                        width: (12 * zoom) + 'px',
                        height: (12 * zoom) + 'px',
                        borderRadius: '50%',
                        background: '#fff',
                        border: `${2 * zoom}px solid ${color.border}`,
                        cursor: 'pointer',
                        zIndex: 10,
                        pointerEvents: 'auto'
                    },
                    onMouseDown: (e) => e.stopPropagation(),
                    onClick: (e) => handleInputClick(e, node),
                    title: connectingFrom ? t('Click to complete connection') : t('Input')
                }),
                
                ...(function() {
                    const nodeType = node.data.nodeType;
                    
                    if (nodeType === 'branch' && node.data.output_groups && node.data.output_groups.length > 0) {
                        return node.data.output_groups.map((group, index) => {
                            const totalGroups = node.data.output_groups.length;
                            const handleLeft = ((index + 1) / (totalGroups + 1)) * 100;
                            const isThisHandleConnecting = connectingFrom === node.id + ':' + group.id;
                            const tooltip = group.conditions.map(c => c.value).join(' ' + (group.logic === 'or' ? t('OR') : t('AND')) + ' ');
                            
                            return h('div', {
                                key: 'output-' + group.id,
                                style: {
                                    position: 'absolute',
                                    left: handleLeft + '%',
                                    bottom: (-8 * zoom) + 'px',
                                    transform: 'translateX(-50%)',
                                    width: (12 * zoom) + 'px',
                                    height: (12 * zoom) + 'px',
                                    borderRadius: '50%',
                                    background: isThisHandleConnecting ? '#ff9800' : color.border,
                                    border: `${2 * zoom}px solid #fff`,
                                    cursor: 'pointer',
                                    zIndex: 10,
                                    boxShadow: isThisHandleConnecting ? '0 0 0 4px rgba(255,152,0,0.3)' : 'none',
                                    pointerEvents: 'auto'
                                },
                                onMouseDown: (e) => e.stopPropagation(),
                                onClick: (e) => {
                                    e.stopPropagation();
                                    handleOutputClick(e, node, group.id);
                                },
                                title: tooltip || t('Output %s').replace('%s', index + 1)
                            });
                        });
                    }
                    
                    if (node.data.nodeType === 'loop') {
                        // Show handles on BOTH sides — pass side as handleId so arc knows which way to sweep
                        return ['left', 'right'].map(s => {
                            const isThisSideConnecting = connectingFrom === node.id + ':' + s;
                            return h('div', {
                                key: 'output-handle-' + s,
                                style: {
                                    position: 'absolute',
                                    [s === 'left' ? 'left' : 'right']: (-8 * zoom) + 'px',
                                    top: '50%',
                                    transform: 'translateY(-50%)',
                                    width: (12 * zoom) + 'px',
                                    height: (12 * zoom) + 'px',
                                    borderRadius: '50%',
                                    background: isThisSideConnecting ? '#ff9800' : color.border,
                                    border: `${2 * zoom}px solid #fff`,
                                    cursor: 'pointer',
                                    zIndex: 10,
                                    boxShadow: isThisSideConnecting ? '0 0 0 4px rgba(255,152,0,0.3)' : 'none',
                                    pointerEvents: 'auto'
                                },
                                onMouseDown: (e) => e.stopPropagation(),
                                onClick: (e) => handleOutputClick(e, node, s),
                                title: isThisSideConnecting ? t('Click again to cancel') : (s === 'left' ? t('Connect from left') : t('Connect from right'))
                            });
                        });
                    }

                    return [h('div', {
                        key: 'output-handle',
                        style: {
                            position: 'absolute',
                            left: '50%',
                            bottom: (-8 * zoom) + 'px',
                            transform: 'translateX(-50%)',
                            width: (12 * zoom) + 'px',
                            height: (12 * zoom) + 'px',
                            borderRadius: '50%',
                            background: isConnecting ? '#ff9800' : color.border,
                            border: `${2 * zoom}px solid #fff`,
                            cursor: 'pointer',
                            zIndex: 10,
                            boxShadow: isConnecting ? '0 0 0 4px rgba(255,152,0,0.3)' : 'none',
                            pointerEvents: 'auto'
                        },
                        onMouseDown: (e) => e.stopPropagation(),
                        onClick: (e) => handleOutputClick(e, node),
                        title: isConnecting ? t('Click again to cancel') : t('Click to start connection')
                    })];
                })(),
                
                h('div', {
                    key: 'content-wrapper',
                    style: {
                        pointerEvents: 'none',
                        userSelect: 'none'
                    }
                }, [
                    h('div', {
                        key: 'type',
                        style: {
                            fontWeight: 'bold',
                            fontSize: (10 * zoom) + 'px',
                            color: color.text,
                            marginBottom: (8 * zoom) + 'px',
                            textTransform: 'uppercase',
                            letterSpacing: '0.5px'
                        }
                    }, isMessageNode ? t('Message') : (nodeTypeLabels[node.data.nodeType] || t('Node'))),
                    h('div', {
                        key: 'label',
                        style: {
                            fontSize: (13 * zoom) + 'px',
                            color: '#333',
                            lineHeight: '1.4'
                        }
                    }, (() => {
                        // Loop nodes: display target dynamically from the edge (wire is source of truth)
                        if (node.data.nodeType === 'loop') {
                            const loopEdge = edges.find(e => e.source === node.id);
                            if (loopEdge) {
                                const targetNode = nodes.find(n => n.id === loopEdge.target);
                                if (targetNode) {
                                    const targetLabel = targetNode.data.message
                                                     || targetNode.data.label
                                                     || targetNode.data.nodeType
                                                     || targetNode.id;
                                    return '↩ ' + String(targetLabel).substring(0, 30);
                                }
                            }
                            return '↩ ' + t('(drag a wire to set target)');
                        }
                        // Translate UI-only labels without changing saved message/config data.
                        const type = node.data.nodeType;
                        if (type === 'ai_chat') return t('AI Chat');
                        if (type === 'action') {
                            const cfg = node.data.config || {};
                            switch (node.data.action_type) {
                                case 'capture_lead': return t('Capture Lead');
                                case 'redirect':
                                    try {
                                        if (cfg.url) return t('Redirect to %s').replace('%s', new URL(cfg.url).hostname);
                                    } catch (error) {}
                                    return t('Redirect (URL needed)');
                                case 'link_button': {
                                    const icons = { whatsapp: '🟢', telegram: '✈️', messenger: '💬', instagram: '📷', sms: '📱', phone: '📞', email: '📧' };
                                    return cfg.button_label ? (icons[cfg.contact_type] || '🔗') + ' ' + cfg.button_label : t('Link Button (label needed)');
                                }
                                case 'send_email': return t('Email: %s').replace('%s', () => cfg.to || t('Not configured'));
                                case 'set_variable': return t('Set %s').replace('%s', () => cfg.variable_name || t('variable'));
                                case 'tag_lead': return t('Tag: %s').replace('%s', () => (cfg.tags || []).join(', ') || t('No tags'));
                                default: return t('Action (choose type)');
                            }
                        }
                        if (type === 'webhook' && node.data.label === '⚡ Webhook') return '⚡ ' + t('Webhook');
                        if (type === 'branch') {
                            const groups = node.data.output_groups || [];
                            const label = groups.map(group => (group.conditions || []).map(c => c.value)
                                .join(' ' + (group.logic === 'or' ? t('OR') : t('AND')) + ' ')).join(' | ');
                            return label || t('Branch Logic');
                        }
                        if (!node.data.message && !node.data.question && !node.data.output_message) {
                            const canonical = { message: 'Message', question: 'Message / Question', output: 'Output', click: 'Click' };
                            if (node.data.label === canonical[type]) return nodeTypeLabels[type];
                        }
                        return node.data.label || t('Untitled');
                    })())
                ])
            ]);
        });
        
        return h('div', {
            id: 'dialoga-canvas',
            className: 'canvas-background',
            style: {
                width: '100%',
                height: '100%',
                background: '#F7F8FC',
                backgroundImage: 'radial-gradient(circle, #E5E7EF 1px, transparent 1px)',
                backgroundSize: (20 * zoom) + 'px ' + (20 * zoom) + 'px',
                backgroundPosition: panOffset.x + 'px ' + panOffset.y + 'px',
                position: 'relative',
                overflow: 'hidden',
                cursor: isPanning ? 'grabbing' : (connectingFrom ? 'crosshair' : 'grab')
            },
            onDragOver: handleDragOver,
            onDrop: handleDrop,
            onClick: handleCanvasClick,
            onMouseDown: handleCanvasMouseDown,
            onMouseMove: handleCanvasMouseMove,
            onMouseUp: handleCanvasMouseUp
            // v0.40.0 — onWheel removed; see handleWheel comment above.
        }, [
            h('svg', {
                key: 'defs',
                style: { position: 'absolute', width: 0, height: 0 }
            }, [
                h('defs', {}, [
                    h('marker', {
                        id: 'arrowhead',
                        markerWidth: 10,
                        markerHeight: 10,
                        refX: 9,
                        refY: 3,
                        orient: 'auto',
                        markerUnits: 'strokeWidth'
                    }, [
                        h('path', {
                            d: 'M0,0 L0,6 L9,3 z',
                            fill: '#2196f3'
                        })
                    ]),
                    h('marker', {
                        id: 'arrowhead-loop',
                        markerWidth: 10,
                        markerHeight: 10,
                        refX: 9,
                        refY: 3,
                        orient: 'auto',
                        markerUnits: 'strokeWidth'
                    }, [
                        h('path', {
                            d: 'M0,0 L0,6 L9,3 z',
                            fill: '#ff5722'
                        })
                    ])
                ])
            ]),
            
            ...connectionLines,
            connectionPreviewLine,
            
            h('div', {
                key: 'controls',
                style: {
                    position: 'absolute',
                    bottom: '10px',
                    right: '10px',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: '5px',
                    zIndex: 1000
                }
            }, [
                h('button', {
                    key: 'zoom-in',
                    style: {
                        padding: '8px 12px',
                        background: 'white',
                        border: '1px solid #E5E7EF',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        fontSize: '16px',
                        fontWeight: 'bold'
                    },
                    onClick: () => setZoom(Math.min(3, zoom + 0.1)),
                    title: t('Zoom in')
                }, '+'),
                h('button', {
                    key: 'zoom-out',
                    style: {
                        padding: '8px 12px',
                        background: 'white',
                        border: '1px solid #E5E7EF',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        fontSize: '16px',
                        fontWeight: 'bold',
                        lineHeight: '1'
                    },
                    onClick: () => setZoom(Math.max(0.1, zoom - 0.1)),
                    title: t('Zoom out')
                }, '-'),
                h('button', {
                    key: 'reset',
                    style: {
                        padding: '8px 12px',
                        background: 'white',
                        border: '1px solid #E5E7EF',
                        borderRadius: '4px',
                        cursor: 'pointer',
                        fontSize: '12px',
                        fontFamily: 'Arial, sans-serif'
                    },
                    onClick: () => { setZoom(1); setPanOffset({ x: 0, y: 0 }); },
                    title: t('Reset view')
                }, '↻')
            ]),
            
            h('div', {
                key: 'info',
                style: {
                    position: 'absolute',
                    top: '10px',
                    right: '10px',
                    padding: '8px 12px',
                    background: 'white',
                    borderRadius: '4px',
                    fontSize: '12px',
                    color: '#666',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                    zIndex: 1000
                }
            }, [
                h('div', { key: 'nodes' }, (nodes.length === 1 ? t('%s node') : t('%s nodes')).replace('%s', nodes.length)),
                connectingFrom ? h('div', {
                    key: 'connecting',
                    style: { color: '#ff9800', fontWeight: 'bold', marginTop: '4px' }
                }, '🔗 ' + t('Click input to connect')) : null
            ]),
            
            h('div', {
                key: 'zoom-indicator',
                style: {
                    position: 'absolute',
                    top: '10px',
                    left: '10px',
                    padding: '4px 8px',
                    background: 'white',
                    borderRadius: '4px',
                    fontSize: '11px',
                    color: '#666',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                    zIndex: 1000
                }
            }, `${Math.round(zoom * 100)}%`),
            
            // "Chat Starts Here" start indicator — interactive, acts as flow start node
            (() => {
                const hasStartEdge = edges.some(e => e.source === '__start__');
                const isStartConnecting = connectingFrom === '__start__';
                return h('div', {
                    key: 'start-indicator',
                    style: {
                        position: 'absolute',
                        left: (300 * zoom + panOffset.x) + 'px',
                        top: (28 * zoom + panOffset.y) + 'px',
                        transform: 'translateX(-50%)',
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        gap: 0,
                        zIndex: 2
                    }
                }, [
                    h('div', {
                        key: 'badge',
                        style: {
                            padding: (6 * zoom) + 'px ' + (14 * zoom) + 'px',
                            background: '#e8f5e9',
                            border: `${2 * zoom}px dashed #4caf50`,
                            borderRadius: (20 * zoom) + 'px',
                            color: '#2e7d32',
                            fontSize: (11 * zoom) + 'px',
                            fontWeight: '700',
                            whiteSpace: 'nowrap',
                            letterSpacing: '0.5px',
                            textTransform: 'uppercase',
                            pointerEvents: 'none'
                        }
                    }, '▶  ' + t('Chat starts here')),
                    // Output handle — click to connect to first node
                    h('div', {
                        key: 'start-handle',
                        'data-start-handle': 'true',
                        style: {
                            width: (12 * zoom) + 'px',
                            height: (12 * zoom) + 'px',
                            borderRadius: '50%',
                            background: isStartConnecting ? '#ff9800' : '#4caf50',
                            border: `${2 * zoom}px solid #fff`,
                            cursor: 'pointer',
                            zIndex: 10,
                            marginTop: (4 * zoom) + 'px',
                            boxShadow: isStartConnecting ? '0 0 0 4px rgba(255,152,0,0.3)' : '0 0 0 2px rgba(76,175,80,0.3)',
                            transition: 'background 0.2s, box-shadow 0.2s'
                        },
                        onMouseDown: (e) => e.stopPropagation(),
                        onClick: (e) => {
                            e.stopPropagation();
                            if (isStartConnecting) {
                                setConnectingFrom(null);
                                setConnectionPreviewMouse(null);
                            } else {
                                setConnectingFrom('__start__');
                            }
                        },
                        title: isStartConnecting ? t('Click again to cancel') : t('Click to connect to first node')
                    }),
                    // Static hint arrow — only when not yet connected
                    !hasStartEdge && !isStartConnecting ? h('div', {
                        key: 'arrow',
                        style: {
                            width: 0,
                            height: (14 * zoom) + 'px',
                            borderLeft: `${2 * zoom}px dashed #4caf50`,
                            pointerEvents: 'none'
                        }
                    }) : null,
                    !hasStartEdge && !isStartConnecting ? h('div', {
                        key: 'arrowhead',
                        style: {
                            width: 0,
                            height: 0,
                            borderLeft: (6 * zoom) + 'px solid transparent',
                            borderRight: (6 * zoom) + 'px solid transparent',
                            borderTop: (8 * zoom) + 'px solid #4caf50',
                            pointerEvents: 'none'
                        }
                    }) : null
                ]);
            })(),

            ...nodeElements,

            nodes.length === 0 ? h('div', {
                key: 'empty',
                style: {
                    position: 'absolute',
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    textAlign: 'center',
                    color: '#999',
                    fontSize: '14px'
                }
            }, [
                h('img', {
                    key: 'icon',
                    src: (window.dialogaAdmin && window.dialogaAdmin.logoUrl) || '',
                    alt: 'Dialoga',
                    style: { height: '80px', width: 'auto', marginBottom: '14px', opacity: '0.4' },
                    onerror: function(e) { e.target.style.display = 'none'; }
                }),
                h('div', { key: 'text' }, t('Drag nodes from palette above or click buttons to add'))
            ]) : null
        ]);
    }
    
    window.dialogaCanvasComponent = FlowCanvas;
    _log('✅✅✅ DIALOGA Canvas v0.28.9 ready! ✅✅✅');
    
})();
