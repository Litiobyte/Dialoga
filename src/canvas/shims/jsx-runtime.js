import React from './react.js';

export const Fragment = React.Fragment;
export const jsx = (type, props, key) => React.createElement(type, { ...props, key });
export const jsxs = jsx;
export const jsxDEV = jsx;
