import { confirm } from './useConsole';

/**
 * Ask first, then post: `const ask = useConfirmedPost(post); ask({ message: 'Deploy?', ok: 'Deploy' }, '/site/deploy')`.
 * `post` is a function (url, data) such as the one usePost() returns.
 */
export function useConfirmedPost(post) {
    return async (options, url, data = {}) => {
        if (await confirm(options)) {
            post(url, data);
        }
    };
}
